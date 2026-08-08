<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST,GET,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-Key, X-Task-Key');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

const DEVICE_TIMEOUT = 300;
const TASK_EXPIRE_SECONDS = 1800;
const TASK_EXECUTION_LIMIT_SECONDS = 86400;

function agent_json($ok, $data = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function agent_body() {
  static $data = null;
  if ($data !== null) return $data;
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) parse_str($raw, $data);
  if (!is_array($data)) $data = [];
  return $data;
}

function ensure_tables() {
  db()->exec("CREATE TABLE IF NOT EXISTS device_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,username VARCHAR(50) NOT NULL,
    device_name VARCHAR(100) NOT NULL DEFAULT '',device_key_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',last_seen INT NOT NULL DEFAULT 0,created_at INT NOT NULL,
    KEY idx_device_user (user_id),UNIQUE KEY uniq_device_hash (device_key_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS auth_rate_limit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(160) NOT NULL,
    bucket_hash CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt INT NOT NULL DEFAULT 0,
    locked_until INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_bucket_hash (bucket_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS platform_notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at INT NOT NULL,
    expires_at INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $columns = [
    ['mail_job_recipients','claimed_at',"ALTER TABLE mail_job_recipients ADD COLUMN claimed_at INT NOT NULL DEFAULT 0"],
    ['mail_job_recipients','claimed_device_id',"ALTER TABLE mail_job_recipients ADD COLUMN claimed_device_id INT NOT NULL DEFAULT 0"],
    ['mail_jobs','task_key',"ALTER TABLE mail_jobs ADD COLUMN task_key VARCHAR(40) DEFAULT NULL"],
    ['mail_jobs','task_key_expires_at',"ALTER TABLE mail_jobs ADD COLUMN task_key_expires_at INT NOT NULL DEFAULT 0"],
    ['mail_jobs','smtp_group_id',"ALTER TABLE mail_jobs ADD COLUMN smtp_group_id INT NOT NULL DEFAULT 0"],
    ['mail_jobs','owner_user_id',"ALTER TABLE mail_jobs ADD COLUMN owner_user_id INT NOT NULL DEFAULT 0"],
    ['mail_jobs','execution_deadline',"ALTER TABLE mail_jobs ADD COLUMN execution_deadline INT NOT NULL DEFAULT 0"],
  ];
  foreach ($columns as [$table,$column,$sql]) {
    $check = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([$table,$column]);
    if (!$check->fetchColumn()) db()->exec($sql);
  }
  db()->exec("UPDATE mail_jobs SET execution_deadline=started_at+86400 WHERE status='running' AND execution_deadline=0 AND started_at>0");
}

function cancel_expired_tasks() {
  db()->prepare("UPDATE mail_jobs SET status='cancelled',error='30分钟未执行，任务已自动取消',finished_at=? WHERE status='pending' AND task_key IS NOT NULL AND task_key_expires_at>0 AND task_key_expires_at<?")
    ->execute([now(), now()]);
}

function expire_overtime_tasks() {
  $timestamp = now();
  $stmt = db()->prepare("SELECT id FROM mail_jobs WHERE status IN ('pending','running') AND ((execution_deadline>0 AND execution_deadline<=?) OR (status='running' AND execution_deadline=0 AND started_at>0 AND started_at<=?))");
  $stmt->execute([$timestamp, $timestamp - TASK_EXECUTION_LIMIT_SECONDS]);
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare("UPDATE mail_job_recipients SET status='failed',error='任务执行超过24小时，未发送部分已自动结束',sent_at=?,claimed_at=0,claimed_device_id=0 WHERE job_id=? AND status IN ('pending','running')")
        ->execute([$timestamp, $jobId]);
      $counts = $pdo->prepare("SELECT SUM(status='sent') sent_count,SUM(status='failed') failed_count FROM mail_job_recipients WHERE job_id=?");
      $counts->execute([$jobId]);
      $stats = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
      $sent = intval($stats['sent_count'] ?? 0);
      $failed = intval($stats['failed_count'] ?? 0);
      $pdo->prepare("UPDATE mail_jobs SET status=?,sent_count=?,failed_count=?,finished_at=?,error='执行超过24小时，任务已自动结束' WHERE id=? AND status IN ('pending','running')")
        ->execute([$sent > 0 ? 'partial' : 'cancelled', $sent, $failed, $timestamp, $jobId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

function active_device($key, $touch = true) {
  $key = trim($key);
  if ($key === '') return null;
  $stmt = db()->prepare("SELECT d.*,u.status user_status,u.display_name FROM device_keys d INNER JOIN users u ON u.id=d.user_id WHERE d.device_key_hash=? AND d.status='active'");
  $stmt->execute([hash('sha256', $key)]);
  $device = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$device || $device['user_status'] !== 'active') return null;
  if ($touch) db()->prepare('UPDATE device_keys SET last_seen=? WHERE id=?')->execute([now(), $device['id']]);
  return $device;
}

function require_device() {
  $device = active_device($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
  if (!$device) agent_json(false, ['code'=>'DEVICE_KEY_INVALID','error'=>'设备密钥无效或已吊销'], 401);
  return $device;
}

// ===== 请求频率限制 (防暴力破解 / 防批量注册) =====
// bucket: 按 IP + 用户名 维度统计
function rate_client_ip() {
  return strval($_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
}

function rate_get($bucket) {
  $hash = hash('sha256', $bucket);
  $stmt = db()->prepare('SELECT attempts,last_attempt,locked_until FROM auth_rate_limit WHERE bucket_hash=?');
  $stmt->execute([$hash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return ['attempts' => 0, 'last_attempt' => 0, 'locked_until' => 0];
  return ['attempts' => intval($row['attempts']), 'last_attempt' => intval($row['last_attempt']), 'locked_until' => intval($row['locked_until'])];
}

// 检查是否被锁定: 返回剩余锁定秒数 (0=未锁定)
function rate_locked($bucket) {
  $r = rate_get($bucket);
  $now = now();
  if ($r['locked_until'] > $now) return $r['locked_until'] - $now;
  if ($r['locked_until'] > 0 && $r['locked_until'] <= $now) {
    // 锁定过期, 自动重置
    db()->prepare('UPDATE auth_rate_limit SET attempts=0,locked_until=0 WHERE bucket_hash=?')
      ->execute([hash('sha256', $bucket)]);
  }
  return 0;
}

// 记录一次尝试 (失败时调用). $window=时间窗口秒, $max=窗口内最大次数, $lockSec=锁定秒数
// 返回: false=未超限; array=超限已锁定 ['locked_for'=>秒数]
function rate_fail($bucket, $window = 600, $max = 5, $lockSec = 900) {
  $hash = hash('sha256', $bucket);
  $now = now();
  $r = rate_get($bucket);
  // 若距离上次尝试超过窗口, 重置计数
  if ($r['last_attempt'] > 0 && ($now - $r['last_attempt']) > $window) {
    $r['attempts'] = 0;
  }
  $attempts = $r['attempts'] + 1;
  $lockedUntil = 0;
  $limitHit = false;
  if ($attempts >= $max) {
    $lockedUntil = $now + $lockSec;
    $limitHit = true;
    $attempts = 0; // 锁定期间计数清零, 解锁后重新计
  }
  db()->prepare('INSERT INTO auth_rate_limit(bucket,bucket_hash,attempts,last_attempt,locked_until) VALUES(?,?,?,?,?)
    ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),last_attempt=VALUES(last_attempt),locked_until=VALUES(locked_until)')
    ->execute([$bucket, $hash, $attempts, $now, $lockedUntil]);
  return $limitHit ? ['locked_for' => $lockSec] : false;
}

// 记录成功 (登录成功时调用, 清零)
function rate_success($bucket) {
  db()->prepare('DELETE FROM auth_rate_limit WHERE bucket_hash=?')->execute([hash('sha256', $bucket)]);
}

// 检查注册频率: $maxPerHour=每小时最大注册数, 超限返回 true (拒绝)
function rate_register_blocked($ip, $maxPerHour = 3) {
  $bucket = 'reg:' . $ip;
  $r = rate_get($bucket);
  $now = now();
  if ($r['last_attempt'] > 0 && ($now - $r['last_attempt']) > 3600) $r['attempts'] = 0;
  $attempts = $r['attempts'] + 1;
  db()->prepare('INSERT INTO auth_rate_limit(bucket,bucket_hash,attempts,last_attempt,locked_until) VALUES(?,?,?,?,?)
    ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),last_attempt=VALUES(last_attempt)')
    ->execute([$bucket, hash('sha256', $bucket), $attempts, $now, 0]);
  return $attempts > $maxPerHour;
}

function task_by_key($key) {
  $key = strtoupper(trim($key));
  if ($key === '') return null;
  $stmt = db()->prepare('SELECT * FROM mail_jobs WHERE task_key=?');
  $stmt->execute([$key]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function safe_task($job) {
  return [
    'id'=>(string)$job['id'],'subject'=>(string)$job['subject'],'status'=>(string)$job['status'],
    'total'=>intval($job['recipient_count']),'sent'=>intval($job['sent_count']),
    'failed'=>intval($job['failed_count']),'remaining'=>max(0,intval($job['recipient_count'])-intval($job['sent_count'])-intval($job['failed_count'])),
    'smtp_count'=>available_smtp_count($job),
    'created_at'=>intval($job['created_at']),'expires_at'=>intval($job['task_key_expires_at'] ?? 0),
    'execution_deadline'=>intval($job['execution_deadline'] ?? 0),
    'task_key'=>(string)($job['task_key'] ?? ''),
  ];
}

function available_smtp_count($job) {
  if (intval($job['smtp_group_id'] ?? 0) > 0) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM smtp_configs WHERE group_id=? AND owner_user_id=? AND host<>'' AND from_email<>'' AND (use_ssl=1 OR use_starttls=1)");
    $stmt->execute([intval($job['smtp_group_id']),intval($job['owner_user_id'])]);
  } else {
    $stmt = db()->prepare("SELECT COUNT(*) FROM smtp_configs WHERE id=? AND owner_user_id=? AND host<>'' AND from_email<>'' AND (use_ssl=1 OR use_starttls=1)");
    $stmt->execute([$job['smtp_config_id'],intval($job['owner_user_id'])]);
  }
  return intval($stmt->fetchColumn());
}

function job_stats($jobId) {
  $stmt = db()->prepare("SELECT j.recipient_count,j.sent_count,j.failed_count,j.status,(SELECT COUNT(*) FROM mail_job_recipients r WHERE r.job_id=j.id AND r.status IN ('pending','running')) remaining FROM mail_jobs j WHERE j.id=?");
  $stmt->execute([$jobId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return ['total'=>intval($row['recipient_count']),'sent'=>intval($row['sent_count']),'failed'=>intval($row['failed_count']),'remaining'=>intval($row['remaining']),'status'=>$row['status']];
}

function auth_job($body) {
  $deviceKey = trim($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
  if ($deviceKey !== '') {
    $device = require_device();
    $jobId = trim($body['job_id'] ?? '');
    $stmt = db()->prepare('SELECT * FROM mail_jobs WHERE id=? AND owner_user_id=?');
    $stmt->execute([$jobId, intval($device['user_id'])]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) agent_json(false, ['code'=>'TASK_NOT_FOUND','error'=>'任务不存在'], 404);
    return [$job,$device];
  }
  $taskKey = trim($_SERVER['HTTP_X_TASK_KEY'] ?? ($body['task_key'] ?? ''));
  $job = task_by_key($taskKey);
  if (!$job) agent_json(false, ['code'=>'TASK_KEY_INVALID','error'=>'任务密钥无效'], 404);
  return [$job,null];
}

ensure_tables();
cancel_expired_tasks();
expire_overtime_tasks();
$action = $_GET['action'] ?? '';
$body = agent_body();

switch ($action) {
  case 'register': {
    $username = trim(strval($body['username'] ?? ''));
    $displayName = trim(strval($body['display_name'] ?? ''));
    $password = strval($body['password'] ?? '');
    $confirm = strval($body['confirm_password'] ?? '');
    $ip = rate_client_ip();
    if (rate_register_blocked($ip, 3)) agent_json(false, ['code'=>'REGISTER_TOO_FAST','error'=>'注册过于频繁，请稍后再试'], 429);
    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $username)) agent_json(false, ['code'=>'BAD_USERNAME','error'=>'用户名需为 4-30 位字母、数字或下划线'], 400);
    if (mb_strlen($displayName) < 2) agent_json(false, ['code'=>'BAD_DISPLAY','error'=>'显示名称至少 2 个字'], 400);
    if (strlen($password) < 6) agent_json(false, ['code'=>'BAD_PASSWORD','error'=>'密码至少 6 位'], 400);
    if ($password !== $confirm) agent_json(false, ['code'=>'PASSWORD_MISMATCH','error'=>'两次输入的密码不一致'], 400);
    $stmt = db()->prepare('SELECT id FROM users WHERE username=?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) agent_json(false, ['code'=>'USERNAME_TAKEN','error'=>'用户名已存在'], 409);
    db()->prepare('INSERT INTO users(username,password_hash,display_name,balance_points,status,role,last_login_at,created_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, 0, 'active', 'user', 0, now()]);
    agent_json(true, ['message' => '注册成功，请登录']);
  }
  case 'login': {
    $username = trim(strval($body['username'] ?? ''));
    $password = strval($body['password'] ?? '');
    if ($username === '' || $password === '') agent_json(false, ['code'=>'CREDENTIAL_REQUIRED','error'=>'请输入用户名和密码'], 400);
    $ip = rate_client_ip();
    $bucket = 'login:' . $ip . ':' . strtolower($username);
    $remaining = rate_locked($bucket);
    if ($remaining > 0) agent_json(false, ['code'=>'LOGIN_LOCKED','error'=>'尝试次数过多，请 ' . intval(ceil($remaining / 60)) . ' 分钟后重试', 'retry_after' => $remaining], 429);
    $stmt = db()->prepare('SELECT * FROM users WHERE username=? AND status=?');
    $stmt->execute([$username, 'active']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, $row['password_hash'])) {
      $lock = rate_fail($bucket, 600, 5, 900);
      if ($lock) agent_json(false, ['code'=>'LOGIN_LOCKED','error'=>'失败次数过多，已锁定 ' . intval(ceil($lock['locked_for'] / 60)) . ' 分钟', 'retry_after' => $lock['locked_for']], 429);
      agent_json(false, ['code'=>'BAD_CREDENTIALS','error'=>'用户名或密码错误'], 401);
    }
    rate_success($bucket);
    db()->prepare('UPDATE users SET last_login_at=? WHERE id=?')->execute([now(), intval($row['id'])]);
    $key = 'dk-' . bin2hex(random_bytes(32));
    $name = mb_substr(trim(strval($body['device_name'] ?? '')), 0, 100);
    if ($name === '') $name = 'App ' . date('m-d H:i');
    db()->prepare('INSERT INTO device_keys(user_id,username,device_name,device_key_hash,status,last_seen,created_at) VALUES(?,?,?,?,?,?,?)')
      ->execute([intval($row['id']), strval($row['username']), $name, hash('sha256', $key), 'active', now(), now()]);
    agent_json(true, [
      'key' => $key,
      'user' => [
        'id' => intval($row['id']),
        'username' => $row['username'],
        'display_name' => $row['display_name'] ?: $row['username'],
        'balance_points' => intval($row['balance_points'] ?? 0),
        'membership_expires_at' => intval($row['membership_expires_at'] ?? 0),
        'membership_active' => intval($row['membership_expires_at'] ?? 0) > now(),
      ],
    ]);
  }
  case 'resolve': {
    $key = trim($body['key'] ?? '');
    if ($key === '') agent_json(false, ['code'=>'KEY_REQUIRED','error'=>'请输入密钥'], 400);
    $device = active_device($key);
    if ($device) agent_json(true, ['kind'=>'device','device'=>['id'=>intval($device['id']),'name'=>$device['device_name']], 'user'=>['username'=>$device['username'],'display_name'=>$device['display_name'] ?: $device['username']]]);
    $job = task_by_key($key);
    if ($job) {
      if ($job['status'] === 'cancelled') agent_json(false, ['code'=>'TASK_CANCELLED','error'=>'任务已取消'], 410);
      if (in_array($job['status'], ['done','partial'], true)) agent_json(false, ['code'=>'TASK_FINISHED','error'=>'任务已完成'], 410);
      agent_json(true, ['kind'=>'task','task'=>safe_task($job)]);
    }
    agent_json(false, ['code'=>'KEY_INVALID','error'=>'无法识别该密钥'], 404);
  }
  case 'bind': {
    $device = require_device();
    agent_json(true, ['device'=>['id'=>intval($device['id']),'name'=>$device['device_name']], 'user'=>['username'=>$device['username'],'display_name'=>$device['display_name'] ?: $device['username']]]);
  }
  case 'heartbeat': {
    $device = require_device();
    agent_json(true, ['ts'=>now(),'username'=>$device['username']]);
  }
  case 'tasks': {
    $device = require_device();
    $stmt = db()->prepare("SELECT * FROM mail_jobs WHERE owner_user_id=? AND status IN ('pending','running','done','partial','cancelled') ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([intval($device['user_id'])]);
    $tasks = array_map('safe_task', $stmt->fetchAll(PDO::FETCH_ASSOC));
    agent_json(true, ['tasks'=>$tasks,'user'=>['username'=>$device['username'],'display_name'=>$device['display_name'] ?: $device['username']]]);
  }
  case 'task': {
    [$job,$device] = auth_job($body);
    agent_json(true, ['task'=>safe_task($job),'stats'=>job_stats($job['id'])]);
  }
  case 'claim': {
    [$job,$device] = auth_job($body);
    if ($job['status'] === 'cancelled') agent_json(false, ['code'=>'TASK_CANCELLED','error'=>'任务已取消'], 410);
    if (in_array($job['status'], ['done','partial'], true)) agent_json(false, ['code'=>'TASK_FINISHED','error'=>'任务已完成'], 410);
    $deviceId = $device ? intval($device['id']) : 0;
    $ownerId = intval($job['owner_user_id']);
    if (intval($job['smtp_group_id'] ?? 0) > 0) {
      $cfg = db()->prepare("SELECT * FROM smtp_configs WHERE group_id=? AND owner_user_id=? AND host<>'' AND from_email<>'' AND (use_ssl=1 OR use_starttls=1) ORDER BY RAND() LIMIT 1");
      $cfg->execute([intval($job['smtp_group_id']),$ownerId]);
    } else {
      $cfg = db()->prepare("SELECT * FROM smtp_configs WHERE id=? AND owner_user_id=? AND host<>'' AND from_email<>'' AND (use_ssl=1 OR use_starttls=1)");
      $cfg->execute([$job['smtp_config_id'],$ownerId]);
    }
    $smtp = $cfg->fetch(PDO::FETCH_ASSOC);
    if (!$smtp) agent_json(false, ['code'=>'SMTP_NOT_FOUND','error'=>'本次任务没有可用的加密 SMTP 邮箱'], 409);
    $pdo = db();
    $pdo->beginTransaction();
    try {
      // Delivery may have succeeded before the App lost connectivity. Do not retry unknown deliveries.
      $stale = $pdo->prepare("SELECT COUNT(*) FROM mail_job_recipients WHERE job_id=? AND status='running' AND claimed_at<?");
      $stale->execute([$job['id'], now()-DEVICE_TIMEOUT]);
      $staleCount = intval($stale->fetchColumn());
      if ($staleCount > 0) {
        $expire = $pdo->prepare("UPDATE mail_job_recipients SET status='failed',error='发送结果回传超时，为防止重复邮件未自动重发',sent_at=?,claimed_at=0,claimed_device_id=0 WHERE job_id=? AND status='running' AND claimed_at<?");
        $expire->execute([now(),$job['id'],now()-DEVICE_TIMEOUT]);
        $pdo->prepare('UPDATE mail_jobs SET failed_count=failed_count+? WHERE id=?')->execute([$staleCount,$job['id']]);
      }
      if ($job['status'] === 'pending') {
        $startedAt = now();
        $pdo->prepare("UPDATE mail_jobs SET status='running',assigned_node_id=?,started_at=?,execution_deadline=CASE WHEN execution_deadline>0 THEN execution_deadline ELSE ? END WHERE id=? AND status='pending'")
          ->execute(['app-' . ($deviceId ?: 'task'),$startedAt,$startedAt + TASK_EXECUTION_LIMIT_SECONDS,$job['id']]);
      }
      $stmt = $pdo->prepare("SELECT id,recipient_email FROM mail_job_recipients WHERE job_id=? AND status='pending' ORDER BY id LIMIT 1 FOR UPDATE");
      $stmt->execute([$job['id']]);
      $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($recipient) $pdo->prepare("UPDATE mail_job_recipients SET status='running',claimed_at=?,claimed_device_id=? WHERE id=? AND status='pending'")->execute([now(),$deviceId,$recipient['id']]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      agent_json(false, ['code'=>'CLAIM_FAILED','error'=>'领取任务失败'], 500);
    }
    if (!$recipient) {
      $stats = job_stats($job['id']);
      if ($stats['remaining'] === 0) {
        $status = $stats['failed'] > 0 ? 'partial' : 'done';
        db()->prepare('UPDATE mail_jobs SET status=?,finished_at=?,error=? WHERE id=?')->execute([$status,now(),$stats['failed'] > 0 ? '部分收件人发送失败' : '',$job['id']]);
        agent_json(false, ['code'=>'TASK_FINISHED','error'=>'任务已完成'], 410);
      }
      agent_json(true, ['task'=>safe_task($job),'recipient'=>null,'stats'=>$stats]);
    }
    agent_json(true, ['job'=>['id'=>$job['id'],'subject'=>$job['subject'],'text_content'=>$job['text_content'],'html_content'=>$job['html_content']],
      'smtp'=>['host'=>$smtp['host'],'port'=>intval($smtp['port']),'user'=>$smtp['user'],'pass'=>(string)$smtp['pass'],'from_email'=>$smtp['from_email'],'from_name'=>$smtp['from_name'],'use_ssl'=>intval($smtp['use_ssl']),'use_starttls'=>intval($smtp['use_starttls'])],
      'recipient'=>['id'=>intval($recipient['id']),'recipient_email'=>$recipient['recipient_email']], 'stats'=>job_stats($job['id'])]);
  }
  case 'report': {
    [$job,$device] = auth_job($body);
    $deviceId = $device ? intval($device['id']) : 0;
    $result = $body['result'] ?? null;
    if (!is_array($result)) agent_json(false, ['code'=>'RESULT_REQUIRED','error'=>'缺少发送结果'], 400);
    $rid = intval($result['recipient_id'] ?? 0);
    $ok = !empty($result['ok']);
    $error = mb_substr(trim(strval($result['error'] ?? '')),0,500);
    $stmt = db()->prepare("UPDATE mail_job_recipients SET status=?,error=?,sent_at=?,claimed_at=0,claimed_device_id=0 WHERE id=? AND job_id=? AND status='running' AND claimed_device_id=?");
    $stmt->execute([$ok?'sent':'failed',$ok?'':$error,now(),$rid,$job['id'],$deviceId]);
    if (!$stmt->rowCount()) agent_json(false, ['code'=>'CLAIM_EXPIRED','error'=>'发送记录已过期，请刷新任务'], 409);
    db()->prepare('UPDATE mail_jobs SET sent_count=sent_count+?,failed_count=failed_count+? WHERE id=?')->execute([$ok?1:0,$ok?0:1,$job['id']]);
    $stats = job_stats($job['id']);
    if ($stats['remaining'] === 0) {
      $status = $stats['failed'] > 0 ? 'partial' : 'done';
      db()->prepare('UPDATE mail_jobs SET status=?,finished_at=?,error=? WHERE id=?')->execute([$status,now(),$stats['failed'] > 0 ? '部分收件人发送失败' : '',$job['id']]);
      $stats['status'] = $status;
    }
    agent_json(true, ['stats'=>$stats]);
  }
  case 'release': {
    [$job,$device] = auth_job($body);
    $deviceId = $device ? intval($device['id']) : 0;
    db()->prepare("UPDATE mail_job_recipients SET status='pending',claimed_at=0,claimed_device_id=0 WHERE job_id=? AND status='running' AND claimed_device_id=?")->execute([$job['id'],$deviceId]);
    agent_json(true, ['released'=>true]);
  }
  case 'update_check': {
    agent_json(true, ['update' => [
      'version_code' => intval(get_setting('app_version_code', '0')),
      'version_name' => get_setting('app_version_name', ''),
      'download_url' => get_setting('app_download_url', ''),
      'message' => get_setting('app_update_msg', ''),
      'force' => get_setting('app_update_force', '0') === '1',
    ]]);
  }

  // ================= 移动端管理接口（设备密钥认证） =================
  case 'me': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $stmt = db()->prepare('SELECT username,display_name,balance_points,membership_expires_at,last_login_at,created_at FROM users WHERE id=?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) agent_json(false, ['code'=>'USER_NOT_FOUND','error'=>'用户不存在'], 404);
    $statsStmt = db()->prepare('SELECT
      (SELECT COUNT(*) FROM user_accounts ua WHERE ua.user_id=? AND ua.status="active") account_count,
      (SELECT COUNT(*) FROM friends f INNER JOIN user_accounts ua ON ua.account_uin=f.account_uin WHERE ua.user_id=? AND ua.status="active") friend_count,
      (SELECT COUNT(*) FROM groups_data g INNER JOIN user_accounts ua ON ua.account_uin=g.account_uin WHERE ua.user_id=? AND ua.status="active") group_count,
      (SELECT COUNT(*) FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE ua.user_id=?) export_count,
      (SELECT COUNT(*) FROM mail_jobs WHERE created_by=?) mail_job_count');
    $statsStmt->execute([$uid, $uid, $uid, $uid, $user['username']]);
    $stats = $statsStmt->fetch();
    $memberExp = intval($user['membership_expires_at'] ?? 0);
    $deviceRows = db()->prepare('SELECT id,device_name,status,last_seen FROM device_keys WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
    $deviceRows->execute([$uid]);
    agent_json(true, [
      'user' => [
        'username' => $user['username'],
        'display_name' => $user['display_name'] ?: $user['username'],
        'balance_points' => intval($user['balance_points'] ?? 0),
        'membership_expires_at' => $memberExp,
        'membership_active' => $memberExp > now(),
        'last_login_at' => intval($user['last_login_at'] ?? 0),
        'created_at' => intval($user['created_at'] ?? 0),
      ],
      'stats' => [
        'account_count' => intval($stats['account_count'] ?? 0),
        'friend_count' => intval($stats['friend_count'] ?? 0),
        'group_count' => intval($stats['group_count'] ?? 0),
        'export_count' => intval($stats['export_count'] ?? 0),
        'mail_job_count' => intval($stats['mail_job_count'] ?? 0),
      ],
      'devices' => $deviceRows->fetchAll(),
      'purchase_url' => get_setting('purchase_url', ''),
    ]);
  }
  case 'notices': {
    $device = require_device();
    $now = now();
    $stmt = db()->prepare("SELECT id,title,content,created_at FROM platform_notices WHERE status='active' AND (expires_at=0 OR expires_at>?) ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$now]);
    $list = [];
    foreach ($stmt->fetchAll() as $n) {
      $list[] = [
        'id' => intval($n['id']),
        'title' => strval($n['title']),
        'content' => strval($n['content']),
        'created_at' => intval($n['created_at']),
      ];
    }
    agent_json(true, ['notices' => $list]);
  }
  case 'ledger': {
    $device = require_device();
    $stmt = db()->prepare('SELECT id,created_at,`change`,balance_after,reason,ref_code FROM points_ledger WHERE user_id=? ORDER BY id DESC LIMIT 20');
    $stmt->execute([intval($device['user_id'])]);
    agent_json(true, ['items' => $stmt->fetchAll()]);
  }
  case 'platform_stats': {
    $device = require_device();
    $sql = 'SELECT
      (SELECT COUNT(*) FROM users WHERE status="active") total_users,
      (SELECT COUNT(*) FROM device_keys WHERE status="active") total_devices,
      (SELECT COUNT(*) FROM user_accounts WHERE status="active") total_accounts,
      (SELECT COUNT(*) FROM smtp_configs WHERE host<>"" AND from_email<>"") total_smtp,
      (SELECT COUNT(*) FROM mail_jobs) total_jobs,
      (SELECT COALESCE(SUM(sent_count),0) FROM mail_jobs) total_sent,
      (SELECT COALESCE(SUM(failed_count),0) FROM mail_jobs) total_failed,
      (SELECT COALESCE(SUM(balance_points),0) FROM users) total_points';
    $row = db()->query($sql)->fetch(PDO::FETCH_ASSOC);
    $statusSql = 'SELECT status,COUNT(*) c FROM mail_jobs GROUP BY status';
    $statusMap = [];
    foreach (db()->query($statusSql) as $r) { $statusMap[$r['status']] = intval($r['c']); }
    agent_json(true, [
      'platform' => [
        'users' => intval($row['total_users'] ?? 0),
        'devices' => intval($row['total_devices'] ?? 0),
        'accounts' => intval($row['total_accounts'] ?? 0),
        'smtp' => intval($row['total_smtp'] ?? 0),
        'jobs' => intval($row['total_jobs'] ?? 0),
        'sent' => intval($row['total_sent'] ?? 0),
        'failed' => intval($row['total_failed'] ?? 0),
        'points' => intval($row['total_points'] ?? 0),
      ],
      'job_status' => [
        'pending' => intval($statusMap['pending'] ?? 0),
        'running' => intval($statusMap['running'] ?? 0),
        'done' => intval($statusMap['done'] ?? 0),
        'partial' => intval($statusMap['partial'] ?? 0),
        'failed' => intval($statusMap['failed'] ?? 0),
        'cancelled' => intval($statusMap['cancelled'] ?? 0),
      ],
    ]);
  }
  case 'smtp_groups': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $groupStmt = db()->prepare('SELECT g.*,COUNT(c.id) config_count FROM smtp_groups g LEFT JOIN smtp_configs c ON c.group_id=g.id AND c.owner_user_id=g.owner_user_id WHERE g.owner_user_id=? AND g.owner_role=? GROUP BY g.id,g.name,g.owner_user_id,g.owner_username,g.owner_role,g.created_at ORDER BY g.created_at,g.id');
    $groupStmt->execute([$uid, 'user']);
    $groups = $groupStmt->fetchAll();
    $configs = node_api('GET', '/api/smtp/configs?owner_user_id=' . $uid . '&owner_role=user')['configs'] ?? [];
    $configsByGroup = [];
    foreach ($configs as $c) { $configsByGroup[intval($c['group_id'] ?? 0)][] = $c; }
    $groupList = [];
    foreach ($groups as $g) {
      $groupList[] = ['id' => intval($g['id']), 'name' => $g['name'], 'config_count' => intval($g['config_count'] ?? 0), 'configs' => $configsByGroup[intval($g['id'])] ?? []];
    }
    $ungrouped = $configsByGroup[0] ?? [];
    agent_json(true, ['groups' => $groupList, 'ungrouped' => $ungrouped]);
  }
  case 'smtp_save': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $username = strval($device['username']);
    $id = strval($body['id'] ?? '');
    $from_email = strtolower(trim(strval($body['from_email'] ?? '')));
    if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) agent_json(false, ['code'=>'INVALID_EMAIL','error'=>'请输入正确的邮箱地址'], 400);
    $host = trim(strval($body['host'] ?? ''));
    $port = intval($body['port'] ?? 0);
    $user = trim(strval($body['user'] ?? '')) ?: $from_email;
    $pass = strval($body['pass'] ?? '');
    $from_name = strval($body['from_name'] ?? '');
    $sec = strval($body['security'] ?? '');
    $notes = strval($body['notes'] ?? '');
    $groupId = intval($body['group_id'] ?? 0);
    $providers = [
      'qq.com'=>'ssl','foxmail.com'=>'ssl','163.com'=>'ssl','126.com'=>'ssl','yeah.net'=>'ssl',
      'sina.com'=>'ssl','sina.cn'=>'ssl','aliyun.com'=>'ssl','gmail.com'=>'ssl',
      'outlook.com'=>'starttls','hotmail.com'=>'starttls','live.com'=>'starttls',
    ];
    if ($host === '') {
      $domain = strtolower(substr(strrchr($from_email, '@') ?: '', 1));
      if (isset($providers[$domain])) $host = 'smtp.' . $domain;
    }
    if ($port <= 0) {
      $domain2 = strtolower(substr(strrchr($from_email, '@') ?: '', 1));
      $port = $domain2 === 'outlook.com' || $domain2 === 'hotmail.com' || $domain2 === 'live.com' ? 587 : 465;
    }
    if ($sec === '') {
      $domain3 = strtolower(substr(strrchr($from_email, '@') ?: '', 1));
      $sec = $providers[$domain3] ?? 'starttls';
    }
    if ($host === '') agent_json(false, ['code'=>'HOST_REQUIRED','error'=>'未识别该邮箱，请手动填写 SMTP 服务器'], 400);
    $isAdd = ($id === '');
    if ($isAdd && trim($pass) === '') agent_json(false, ['code'=>'PASS_REQUIRED','error'=>'请输入邮箱 SMTP 授权码'], 400);
    if ($groupId > 0) {
      $g = db()->prepare('SELECT id FROM smtp_groups WHERE id=? AND owner_user_id=? AND owner_role=?');
      $g->execute([$groupId, $uid, 'user']);
      if (!$g->fetch()) agent_json(false, ['code'=>'GROUP_NOT_FOUND','error'=>'邮箱分组不存在'], 400);
    }
    $payload = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
      'from_email' => $from_email, 'from_name' => $from_name, 'notes' => $notes,
      'use_ssl' => $sec === 'ssl' ? 1 : 0, 'use_starttls' => $sec === 'starttls' ? 1 : 0, 'group_id' => $groupId,
      'owner_user_id' => $uid, 'owner_username' => $username, 'owner_role' => 'user'];
    try {
      if ($isAdd) {
        $res = node_api('POST', '/api/smtp/configs', $payload);
      } else {
        if (!$pass) unset($payload['pass']);
        $res = node_api('PUT', '/api/smtp/configs/' . urlencode($id), $payload);
      }
      agent_json(true, ['config' => $res['config'] ?? null]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'SMTP_SAVE_FAILED','error'=>'保存失败：' . $e->getMessage()], 500);
    }
  }
  case 'smtp_delete': {
    $device = require_device();
    $id = strval($body['id'] ?? '');
    if ($id === '') agent_json(false, ['code'=>'ID_REQUIRED','error'=>'缺少配置编号'], 400);
    try {
      node_api('DELETE', '/api/smtp/configs/' . urlencode($id), ['owner_user_id' => intval($device['user_id']), 'owner_role' => 'user']);
      agent_json(true, ['deleted' => true]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'SMTP_DELETE_FAILED','error'=>'删除失败：' . $e->getMessage()], 500);
    }
  }
  case 'smtp_group_save': {
    $device = require_device();
    $name = trim(strval($body['name'] ?? ''));
    if ($name === '') agent_json(false, ['code'=>'NAME_REQUIRED','error'=>'请输入分组名称'], 400);
    db()->prepare('INSERT INTO smtp_groups(name,owner_user_id,owner_username,owner_role,created_at) VALUES(?,?,?,?,?)')
      ->execute([mb_substr($name, 0, 100), intval($device['user_id']), strval($device['username']), 'user', time()]);
    agent_json(true, ['id' => intval(db()->lastInsertId())]);
  }
  case 'smtp_group_delete': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $groupId = intval($body['group_id'] ?? 0);
    $g = db()->prepare('SELECT id FROM smtp_groups WHERE id=? AND owner_user_id=? AND owner_role=?');
    $g->execute([$groupId, $uid, 'user']);
    if (!$g->fetch()) agent_json(false, ['code'=>'GROUP_NOT_FOUND','error'=>'分组不存在'], 404);
    db()->prepare('UPDATE smtp_configs SET group_id=0 WHERE group_id=? AND owner_user_id=?')->execute([$groupId, $uid]);
    db()->prepare('DELETE FROM smtp_groups WHERE id=? AND owner_user_id=?')->execute([$groupId, $uid]);
    agent_json(true, ['deleted' => true]);
  }
  case 'task_create': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $username = strval($device['username']);
    $groupId = intval($body['group_id'] ?? 0);
    $exportId = intval($body['export_id'] ?? 0);
    $recipients = trim(strval($body['recipients'] ?? ''));
    $subject = trim(strval($body['subject'] ?? ''));
    $text = trim(strval($body['content'] ?? ''));
    $html = trim(strval($body['html'] ?? ''));
    $balance = intval(db()->query('SELECT balance_points FROM users WHERE id=' . $uid)->fetchColumn() ?: 0);
    if ($balance <= 0) agent_json(false, ['code'=>'NO_POINTS','error'=>'点数不足，请先充值'], 400);
    if (!$groupId) agent_json(false, ['code'=>'GROUP_REQUIRED','error'=>'请选择邮箱分组'], 400);
    if (!$exportId && !$recipients) agent_json(false, ['code'=>'RECIPIENTS_REQUIRED','error'=>'请选择导出记录或填写收件人'], 400);
    try {
      $res = node_api('POST', '/api/mail/jobs', [
        'group_id' => $groupId,
        'export_record_id' => $exportId,
        'recipients' => $recipients,
        'subject' => $subject ?: 'QQ号列表',
        'text_content' => $text,
        'html_content' => $html,
        'created_by' => $username,
        'owner_user_id' => $uid,
        'owner_role' => 'user',
      ]);
      db()->prepare('UPDATE users SET balance_points=balance_points-1 WHERE id=? AND balance_points>0')->execute([$uid]);
      $after = intval(db()->query('SELECT balance_points FROM users WHERE id=' . $uid)->fetchColumn() ?: 0);
      db()->prepare('INSERT INTO points_ledger(user_id,`change`,balance_after,reason,ref_code,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$uid, -1, $after, 'mail_job', strval($res['job_id'] ?? ''), now()]);
      agent_json(true, ['job_id' => $res['job_id'] ?? null, 'task_key' => $res['task_key'] ?? '', 'recipient_count' => intval($res['recipient_count'] ?? 0), 'balance' => $after]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'TASK_CREATE_FAILED','error'=>'创建任务失败：' . $e->getMessage()], 500);
    }
  }
  case 'task_stats': {
    $device = require_device();
    $username = strval($device['username']);
    if (function_exists('expire_overtime_tasks')) expire_overtime_tasks();
    db()->prepare("UPDATE mail_jobs SET status='cancelled',error='30分钟未执行，任务已自动取消',finished_at=? WHERE status='pending' AND task_key IS NOT NULL AND created_at<?")
      ->execute([time(), time() - 1800]);
    $jobs = [];
    $stmt = db()->prepare("SELECT m.id,m.subject,m.recipient_count,m.sent_count,m.failed_count,m.status,m.created_at,m.task_key,m.task_key_expires_at,m.execution_deadline,
      (SELECT COUNT(*) FROM mail_job_recipients r WHERE r.job_id=m.id AND r.status IN ('pending','running')) AS remaining
      FROM mail_jobs m WHERE m.created_by=? ORDER BY m.created_at DESC LIMIT 50");
    $stmt->execute([$username]);
    foreach ($stmt->fetchAll() as $j) {
      $jobs[] = [
        'id' => $j['id'],
        'subject' => $j['subject'],
        'total' => intval($j['recipient_count']),
        'sent' => intval($j['sent_count']),
        'failed' => intval($j['failed_count']),
        'remaining' => intval($j['remaining']),
        'status' => $j['status'],
        'created_at' => intval($j['created_at']),
        'task_key' => strval($j['task_key'] ?? ''),
        'task_key_expires_at' => intval($j['task_key_expires_at']),
        'execution_deadline' => intval($j['execution_deadline'] ?? 0),
      ];
    }
    agent_json(true, ['jobs' => $jobs]);
  }
  case 'redeem': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $username = strval($device['username']);
    $code = strtoupper(trim(strval($body['code'] ?? '')));
    if ($code === '') agent_json(false, ['code'=>'CODE_REQUIRED','error'=>'请输入卡密'], 400);
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("SELECT * FROM cards WHERE code=? AND status='unused' FOR UPDATE");
      $stmt->execute([$code]);
      $card = $stmt->fetch();
      if (!$card) { $pdo->rollBack(); agent_json(false, ['code'=>'CARD_INVALID','error'=>'卡密无效、已使用或已禁用'], 400); }
      if (intval($card['expires_at']) > 0 && intval($card['expires_at']) < now()) {
        $pdo->rollBack(); agent_json(false, ['code'=>'CARD_EXPIRED','error'=>'卡密已超过有效期'], 400);
      }
      $upd = $pdo->prepare("UPDATE cards SET status='used',used_by=?,used_username=?,used_at=? WHERE code=? AND status='unused'");
      $upd->execute([$uid, $username, now(), $code]);
      if ($upd->rowCount() === 0) { $pdo->rollBack(); agent_json(false, ['code'=>'CARD_USED','error'=>'卡密已被使用'], 400); }
      $addDays = 0; $addPoints = 0;
      $grantPoints = intval($card['grant_points'] ?? 0);
      if ($card['card_type'] === 'days') {
        $addDays = intval($card['days']); $addPoints = $grantPoints;
      } elseif ($card['card_type'] === 'points') {
        $addPoints = intval($card['points']);
      } else {
        $addDays = intval($card['days'] ?: $card['combo_days']);
        $addPoints = intval($card['points']) + $grantPoints;
      }
      $newExp = 0;
      if ($addDays > 0) {
        $exp = intval($pdo->query('SELECT membership_expires_at FROM users WHERE id=' . $uid)->fetchColumn() ?: 0);
        $base = max(now(), $exp);
        $newExp = $base + $addDays * 86400;
        $pdo->prepare('UPDATE users SET membership_expires_at=? WHERE id=?')->execute([$newExp, $uid]);
      }
      $newBalance = intval($pdo->query('SELECT balance_points FROM users WHERE id=' . $uid)->fetchColumn() ?: 0);
      if ($addPoints > 0) {
        $pdo->prepare('UPDATE users SET balance_points=balance_points+? WHERE id=?')->execute([$addPoints, $uid]);
        $newBalance = intval($pdo->query('SELECT balance_points FROM users WHERE id=' . $uid)->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO points_ledger(user_id,`change`,balance_after,reason,ref_code,created_at) VALUES(?,?,?,?,?,?)')
          ->execute([$uid, $addPoints, $newBalance, 'redeem_card', $code, now()]);
      }
      $pdo->commit();
      $parts = [];
      if ($addDays > 0) $parts[] = '会员 +' . $addDays . ' 天';
      if ($addPoints > 0) $parts[] = '点数 +' . $addPoints;
      agent_json(true, ['message' => '激活成功：' . implode('，', $parts), 'membership_expires_at' => $newExp, 'balance_points' => $newBalance]);
    } catch (Exception $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      agent_json(false, ['code'=>'REDEEM_FAILED','error'=>'激活失败，请稍后重试'], 500);
    }
  }
  case 'device_generate': {
    $device = require_device();
    $name = mb_substr(trim(strval($body['device_name'] ?? '')), 0, 100);
    $key = 'dk-' . bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO device_keys(user_id,username,device_name,device_key_hash,status,last_seen,created_at) VALUES(?,?,?,?,?,?,?)')
      ->execute([intval($device['user_id']), strval($device['username']), $name, hash('sha256', $key), 'active', 0, now()]);
    agent_json(true, ['key' => $key]);
  }
  case 'device_revoke': {
    $device = require_device();
    $id = intval($body['id'] ?? 0);
    db()->prepare('UPDATE device_keys SET status=? WHERE id=? AND user_id=?')->execute(['disabled', $id, intval($device['user_id'])]);
    agent_json(true, ['revoked' => true]);
  }
  case 'exports': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $stmt = db()->prepare('SELECT er.id,er.account_uin,er.export_type,er.group_id,er.line_count,er.created_at,g.group_name FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin LEFT JOIN groups_data g ON g.account_uin=er.account_uin AND g.group_id=er.group_id WHERE ua.user_id=? AND er.line_count>0 ORDER BY er.created_at DESC LIMIT 100');
    $stmt->execute([$uid]);
    agent_json(true, ['exports' => $stmt->fetchAll()]);
  }
  case 'export_content': {
    $device = require_device();
    $id = intval($body['id'] ?? 0);
    $record = null;
    $stmt = db()->prepare('SELECT er.* FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE er.id=? AND ua.user_id=? LIMIT 1');
    $stmt->execute([$id, intval($device['user_id'])]);
    $record = $stmt->fetch();
    if (!$record) agent_json(false, ['code'=>'EXPORT_NOT_FOUND','error'=>'导出记录不存在'], 404);
    try {
      $data = node_api('GET', '/api/exports/' . $id);
      agent_json(true, ['content' => strval($data['content'] ?? ''), 'line_count' => intval($record['line_count'] ?? 0)]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'EXPORT_READ_FAILED','error'=>'导出文件不存在'], 404);
    }
  }
  case 'export_delete': {
    $device = require_device();
    $id = intval($body['id'] ?? 0);
    $stmt = db()->prepare('SELECT er.id FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE er.id=? AND ua.user_id=? LIMIT 1');
    $stmt->execute([$id, intval($device['user_id'])]);
    if (!$stmt->fetch()) agent_json(false, ['code'=>'EXPORT_NOT_FOUND','error'=>'导出记录不存在'], 404);
    try {
      node_api('DELETE', '/api/exports/' . $id);
      agent_json(true, ['deleted' => true]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'EXPORT_DELETE_FAILED','error'=>'删除失败，请稍后重试'], 500);
    }
  }
  case 'change_password': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $old = strval($body['old_pass'] ?? '');
    $new = strval($body['new_pass'] ?? '');
    if (strlen($new) < 6) agent_json(false, ['code'=>'PASSWORD_SHORT','error'=>'新密码至少 6 位'], 400);
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([$uid]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($old, $hash)) agent_json(false, ['code'=>'OLD_PASSWORD_WRONG','error'=>'原密码不正确'], 400);
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    agent_json(true, ['changed' => true]);
  }
  case 'accounts': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $stmt = db()->prepare('SELECT ua.account_uin,ua.token AS account_token,ua.display_name AS owned_display_name,ua.created_at AS bind_created_at,a.uin,a.nickname,a.node_id,a.login_status,a.login_error,a.online,a.last_seen,n.name AS node_name,(SELECT COUNT(*) FROM friends f WHERE f.account_uin=ua.account_uin) AS friend_count,(SELECT COUNT(*) FROM groups_data g WHERE g.account_uin=ua.account_uin) AS group_count FROM user_accounts ua LEFT JOIN accounts a ON a.uin=ua.account_uin LEFT JOIN nodes n ON n.node_id=a.node_id WHERE ua.user_id=? ORDER BY ua.created_at DESC LIMIT 100');
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
    $accounts = [];
    foreach ($rows as $a) {
      $uin = strval($a['account_uin'] ?? '') ?: strval($a['uin'] ?? '');
      $status = strval($a['login_status'] ?? 'offline');
      if (!empty($a['online']) && intval($a['last_seen'] ?? 0) >= (time() - 45)) $label = '在线';
      elseif ($status === 'creating') $label = '创建中';
      elseif ($status === 'restarting') $label = '重启中';
      elseif ($status === 'waiting_scan') $label = '等待手机确认';
      elseif ($status === 'waiting_qrcode') $label = '等待二维码';
      elseif ($status === 'create_failed') $label = '创建失败';
      else $label = $status ?: '离线';
      $accounts[] = [
        'uin' => $uin, 'token' => $a['account_token'],
        'nickname' => $a['nickname'] ?: ($a['owned_display_name'] ?: $uin),
        'online' => $label, 'is_online' => $label === '在线',
        'node_name' => $a['node_name'] ?? '', 'friend_count' => intval($a['friend_count'] ?? 0), 'group_count' => intval($a['group_count'] ?? 0),
        'login_error' => $a['login_error'] ?? '',
      ];
    }
    agent_json(true, ['accounts' => $accounts]);
  }
  case 'nodes': {
    $device = require_device();
    $nodes = node_api('GET', '/api/nodes')['nodes'] ?? [];
    $list = [];
    foreach ($nodes as $n) {
      if (empty($n['online'])) continue;
      $cnt = 0;
      try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM accounts WHERE node_id=? AND online=1');
        $stmt->execute([strval($n['node_id'])]);
        $cnt = intval($stmt->fetchColumn());
      } catch (Exception $e) { $cnt = 0; }
      $list[] = [
        'node_id' => strval($n['node_id']),
        'name' => strval($n['name'] ?? $n['node_id']),
        'online' => true,
        'online_count' => $cnt,
      ];
    }
    agent_json(true, ['nodes' => $list]);
  }
  case 'account_add': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $nodeId = trim(strval($body['node_id'] ?? ''));
    if (!$uin || !$nodeId) agent_json(false, ['code'=>'PARAM_REQUIRED','error'=>'请填写 QQ 号并选择节点'], 400);
    $existing = db()->prepare('SELECT id FROM user_accounts WHERE account_uin=?');
    $existing->execute([$uin]);
    if ($existing->fetch()) agent_json(false, ['code'=>'ACCOUNT_TAKEN','error'=>'该账号已被其他用户绑定'], 400);
    $limits = db()->prepare('SELECT max_accounts,max_online_accounts FROM users WHERE id=?');
    $limits->execute([$uid]);
    $limitsRow = $limits->fetch();
    $maxAcct = max(1, intval($limitsRow['max_accounts'] ?? 10));
    $maxOnline = max(1, intval($limitsRow['max_online_accounts'] ?? 2));
    $totalAcct = intval(db()->query('SELECT COUNT(*) FROM user_accounts WHERE user_id=' . $uid)->fetchColumn());
    if ($totalAcct >= $maxAcct) agent_json(false, ['code'=>'ACCOUNT_LIMIT','error'=>'账户数量已达上限（' . $maxAcct . ' 个）'], 400);
    $onlineAcct = intval(db()->query("SELECT COUNT(*) FROM user_accounts ua JOIN accounts a ON a.uin=ua.account_uin WHERE ua.user_id=" . $uid . " AND a.online=1 AND a.last_seen>=" . (time() - 45))->fetchColumn());
    if ($onlineAcct >= $maxOnline) agent_json(false, ['code'=>'ONLINE_LIMIT','error'=>'同时在线账户已达上限（' . $maxOnline . ' 个）'], 400);
    node_api('POST', '/api/accounts', ['uin' => $uin, 'node_id' => $nodeId]);
    db()->prepare('INSERT INTO user_accounts(user_id,username,account_uin,token,display_name,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$uid, strval($device['username']), $uin, account_token(), '', 'active', now(), now()]);
    agent_json(true, ['submitted' => true]);
  }
  case 'account_detail': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    $a = db()->prepare('SELECT ua.account_uin,ua.token AS account_token,ua.display_name AS owned_display_name,a.nickname,a.node_id,a.login_status,a.login_error,a.online,a.last_seen,n.name AS node_name FROM user_accounts ua LEFT JOIN accounts a ON a.uin=ua.account_uin LEFT JOIN nodes n ON n.node_id=a.node_id WHERE ua.user_id=? AND ua.account_uin=? LIMIT 1');
    $a->execute([$uid, $uin]);
    $row = $a->fetch();
    $status = strval($row['login_status'] ?? 'offline');
    if (!empty($row['online']) && intval($row['last_seen'] ?? 0) >= (time() - 45)) $label = '在线';
    elseif ($status === 'creating') $label = '创建中';
    elseif ($status === 'restarting') $label = '重启中';
    elseif ($status === 'waiting_scan') $label = '等待手机确认';
    elseif ($status === 'waiting_qrcode') $label = '等待二维码';
    elseif ($status === 'create_failed') $label = '创建失败';
    else $label = $status ?: '离线';
    agent_json(true, ['account' => [
      'uin' => $uin, 'token' => $row['account_token'],
      'nickname' => $row['nickname'] ?: ($row['owned_display_name'] ?: $uin),
      'node_name' => $row['node_name'] ?? '', 'status' => $label, 'login_error' => $row['login_error'] ?? '',
    ]]);
  }
  case 'account_friends': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $page = max(1, intval($body['page'] ?? 1));
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    $perPage = 50;
    $total = intval(db()->query("SELECT COUNT(*) FROM friends WHERE account_uin='" . db()->quote($uin) . "'")->fetchColumn());
    $stmt = db()->prepare('SELECT friend_uin,nickname,remark,group_name FROM friends WHERE account_uin=? ORDER BY friend_uin LIMIT ' . intval($perPage) . ' OFFSET ' . intval(($page - 1) * $perPage));
    $stmt->execute([$uin]);
    agent_json(true, ['friends' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
  }
  case 'account_groups': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    $stmt = db()->prepare('SELECT group_id,group_name,member_count FROM groups_data WHERE account_uin=? ORDER BY group_id LIMIT 200');
    $stmt->execute([$uin]);
    agent_json(true, ['groups' => $stmt->fetchAll()]);
  }
  case 'account_members': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $groupId = preg_replace('/\D/', '', strval($body['group_id'] ?? ''));
    $page = max(1, intval($body['page'] ?? 1));
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    if ($groupId === '') agent_json(false, ['code'=>'GROUP_REQUIRED','error'=>'缺少群号'], 400);
    $perPage = 50;
    $total = intval(db()->query("SELECT COUNT(*) FROM members WHERE account_uin='" . db()->quote($uin) . "' AND group_id='" . db()->quote($groupId) . "'")->fetchColumn());
    $stmt = db()->prepare('SELECT member_uin,nickname,card,role FROM members WHERE account_uin=? AND group_id=? ORDER BY member_uin LIMIT ' . intval($perPage) . ' OFFSET ' . intval(($page - 1) * $perPage));
    $stmt->execute([$uin, $groupId]);
    agent_json(true, ['members' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
  }
  case 'account_sync': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $action = strval($body['sync_action'] ?? '');
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    if (!in_array($action, ['refresh_friends', 'refresh_groups', 'refresh_members'], true)) agent_json(false, ['code'=>'SYNC_TYPE_WRONG','error'=>'同步类型错误'], 400);
    $path = '/api/accounts/' . urlencode($uin) . '/';
    if ($action === 'refresh_friends') $path .= 'friends/refresh';
    elseif ($action === 'refresh_groups') $path .= 'groups/refresh';
    else $path .= 'groups/' . urlencode(preg_replace('/\D/', '', strval($body['group_id'] ?? ''))) . '/members/refresh';
    try {
      node_api('POST', $path, '{}');
      agent_json(true, ['submitted' => true]);
    } catch (Exception $e) {
      agent_json(false, ['code'=>'SYNC_FAILED','error'=>'同步任务提交失败'], 500);
    }
  }
  case 'account_sync_status': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $action = strval($body['sync_action'] ?? '');
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    if (!in_array($action, ['refresh_friends', 'refresh_groups', 'refresh_members'], true)) agent_json(false, ['code'=>'SYNC_TYPE_WRONG','error'=>'同步类型错误'], 400);
    if ($action === 'refresh_members') {
      $groupId = preg_replace('/\D/', '', strval($body['group_id'] ?? ''));
      $stmt = db()->prepare("SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action='refresh_members' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.group_id'))=? ORDER BY created_at DESC LIMIT 1");
      $stmt->execute([$uin, $groupId]);
    } else {
      $stmt = db()->prepare('SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action=? ORDER BY created_at DESC LIMIT 1');
      $stmt->execute([$uin, $action]);
    }
    agent_json(true, ['job' => $stmt->fetch() ?: null]);
  }
  case 'account_action': {
    $device = require_device();
    $uid = intval($device['user_id']);
    $uin = trim(strval($body['uin'] ?? ''));
    $action = strval($body['action'] ?? '');
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([$uid, $uin]);
    if (!$owner->fetch()) agent_json(false, ['code'=>'ACCOUNT_NOT_FOUND','error'=>'该账号不属于当前用户'], 404);
    if (!in_array($action, ['qrcode', 'refresh_qrcode', 'restart', 'recreate', 'delete'], true)) agent_json(false, ['code'=>'ACTION_WRONG','error'=>'操作类型错误'], 400);
    try {
      if ($action === 'qrcode') {
        agent_json(true, node_api('GET', '/api/accounts/' . urlencode($uin) . '/qrcode'));
      } elseif ($action === 'refresh_qrcode') {
        $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/qrcode/refresh', '{}');
        agent_json(true, ['job_id' => $res['job_id'] ?? null]);
      } elseif ($action === 'restart') {
        $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/restart-container', '{}');
        if (empty($res['ok']) || empty($res['job_id'])) agent_json(false, ['code'=>'RESTART_FAILED','error'=>$res['error'] ?? '容器重启任务创建失败'], 500);
        db()->prepare("UPDATE accounts SET login_status='restarting',login_error='' WHERE uin=?")->execute([$uin]);
        agent_json(true, ['job_id' => $res['job_id']]);
      } elseif ($action === 'recreate') {
        $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/recreate', []);
        if (empty($res['ok']) || empty($res['job_id'])) agent_json(false, ['code'=>'RECREATE_FAILED','error'=>$res['error'] ?? '重新创建任务提交失败'], 500);
        agent_json(true, ['job_id' => $res['job_id']]);
      } else {
        $res = node_api('DELETE', '/api/accounts/' . urlencode($uin) . '?force=1');
        cleanup_account_data($uin);
        agent_json(true, ['deleted' => !empty($res['deleted']), 'job_id' => $res['job_id'] ?? null]);
      }
    } catch (Exception $e) {
      agent_json(false, ['code'=>'ACTION_FAILED','error'=>$e->getMessage()], 500);
    }
  }
  default: agent_json(false, ['code'=>'NOT_FOUND','error'=>'接口不存在'], 404);
}
