<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'REPLACE_WITH_DB_USER');
define('DB_PASS', getenv('DB_PASS') ?: 'REPLACE_WITH_DB_PASS');
define('DB_NAME', getenv('DB_NAME') ?: 'REPLACE_WITH_DB_NAME');
define('NODE_URL', getenv('NODE_URL') ?: 'http://127.0.0.1:28741');
$nodeTokenFile = __DIR__ . '/.private/node_token';
$nodeToken = getenv('SMTP_ADMIN_TOKEN') ?: (is_readable($nodeTokenFile) ? trim(file_get_contents($nodeTokenFile)) : '');
if ($nodeToken === '') throw new RuntimeException('Node API token is not configured');
define('NODE_TOKEN', $nodeToken);

define('GT4_CAPTCHA_ID', getenv('GT4_CAPTCHA_ID') ?: 'REPLACE_WITH_GT4_CAPTCHA_ID');
define('GT4_CAPTCHA_KEY', getenv('GT4_CAPTCHA_KEY') ?: 'REPLACE_WITH_GT4_CAPTCHA_KEY');
define('GT4_API_SERVER', 'https://gcaptcha4.geetest.com');

function geetest_enabled() { return GT4_CAPTCHA_ID !== '' && GT4_CAPTCHA_KEY !== ''; }

function geetest_validate($lot, $output, $token, $gen) {
  if (!$lot || !$output || !$token || !$gen) return false;
  $sign = hash_hmac('sha256', (string)$lot, GT4_CAPTCHA_KEY);
  $url = GT4_API_SERVER . '/validate?captcha_id=' . urlencode(GT4_CAPTCHA_ID);
  $post = [
    'lot_number' => (string)$lot,
    'captcha_output' => (string)$output,
    'pass_token' => (string)$token,
    'gen_time' => (string)$gen,
    'sign_token' => $sign,
  ];
  $ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query($post),
    'timeout' => 10,
  ]]);
  $resp = @file_get_contents($url, false, $ctx);
  if ($resp === false) return true;
  $data = json_decode($resp, true);
  return isset($data['result']) && $data['result'] === 'success';
}

function db() {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    db_bootstrap($pdo);
  }
  return $pdo;
}

function db_bootstrap($pdo) {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
    `key` VARCHAR(64) PRIMARY KEY,
    `value` TEXT NOT NULL,
    updated_at INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $row = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE `key`='purchase_url'")->fetchColumn();
  if (!$row) {
    $st = $pdo->prepare("INSERT INTO site_settings(`key`,`value`,updated_at) VALUES(?,?,?)");
    $st->execute(['purchase_url', 'https://example.com/buy', time()]);
  }
  try { $pdo->exec("ALTER TABLE user_accounts ADD COLUMN token VARCHAR(64) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
  $empty = $pdo->query("SELECT id FROM user_accounts WHERE token=''")->fetchAll(PDO::FETCH_COLUMN);
  if ($empty) {
    $upd = $pdo->prepare("UPDATE user_accounts SET token=? WHERE id=?");
    foreach ($empty as $id) {
      $upd->execute([account_token(), $id]);
    }
  }
  try { $pdo->exec("ALTER TABLE user_accounts ADD UNIQUE KEY uniq_user_account_token (token)"); } catch (Exception $e) {}
  try { $pdo->exec("ALTER TABLE users ADD COLUMN max_accounts INT NOT NULL DEFAULT 10"); } catch (Exception $e) {}
  try { $pdo->exec("ALTER TABLE users ADD COLUMN max_online_accounts INT NOT NULL DEFAULT 2"); } catch (Exception $e) {}
}

function account_token() {
  return 'acct_' . bin2hex(random_bytes(16));
}

function cleanup_account_data($uin) {
  $pdo = db();
  foreach (['user_accounts', 'friends', 'groups_data', 'members', 'export_records'] as $t) {
    $pdo->prepare("DELETE FROM `$t` WHERE account_uin=?")->execute([$uin]);
  }
  $pdo->prepare('DELETE FROM accounts WHERE uin=?')->execute([$uin]);
}

function get_setting($key, $default = '') {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    try {
      foreach (db()->query("SELECT `key`,`value` FROM site_settings") as $r) {
        $cache[$r['key']] = $r['value'];
      }
    } catch (Exception $e) {
    }
  }
  return array_key_exists($key, $cache) ? (string)$cache[$key] : (string)$default;
}

function set_setting($key, $value) {
  $st = db()->prepare("INSERT INTO site_settings(`key`,`value`,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=VALUES(updated_at)");
  $st->execute([$key, (string)$value, time()]);
}

function flash_set($key, $value) { $_SESSION['flash_' . $key] = (string)$value; }
function flash_get($key) {
  $v = $_SESSION['flash_' . $key] ?? '';
  unset($_SESSION['flash_' . $key]);
  return $v;
}

function pager_pages($page, $total) {
  if ($total <= 6) return range(1, $total);
  $pages = [1, 2, 3];
  $pages[] = '...';
  $pages[] = $total - 1;
  $pages[] = $total;
  return $pages;
}

function render_pager($page, $totalPages, $params = []) {
  if ($totalPages <= 1) return;
  $page = max(1, intval($page));
  $totalPages = max(1, intval($totalPages));
  $base = [];
  foreach ($params as $k => $v) {
    if ($v === '' || $v === null) continue;
    $base[(string)$k] = (string)$v;
  }
  echo '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:14px;flex-wrap:wrap">';
  $q = $base; $q['p'] = max(1, $page - 1);
  echo '<a class="btn btn-sm btn-gray" href="?' . http_build_query($q) . '" style="width:auto;opacity:' . ($page <= 1 ? '.5' : '1') . '">上一页</a>';
  foreach (pager_pages($page, $totalPages) as $np) {
    if ($np === '...') { echo '<span style="color:#94a3b8;padding:0 2px">…</span>'; continue; }
    $q = $base; $q['p'] = $np;
    $active = $np === $page;
    echo '<a href="?' . http_build_query($q) . '" style="min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;padding:0 6px;' . ($active ? 'background:#1769e0;color:#fff;font-weight:600' : 'background:#f1f5f9;color:#334155') . '">' . $np . '</a>';
  }
  $q = $base; $q['p'] = min($totalPages, $page + 1);
  echo '<a class="btn btn-sm btn-gray" href="?' . http_build_query($q) . '" style="width:auto;opacity:' . ($page >= $totalPages ? '.5' : '1') . '">下一页</a>';
  echo '<form method="get" style="display:inline-flex;align-items:center;gap:5px;margin-left:6px">';
  foreach ($base as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
  echo '<span style="font-size:12px;color:#6d7c8d">跳转</span>';
  echo '<input type="number" name="p" min="1" max="' . $totalPages . '" value="' . $page . '" style="width:64px;height:32px;border:1px solid #d8dee6;border-radius:8px;text-align:center;font-size:12px">';
  echo '<button type="submit" class="btn btn-sm btn-gray" style="width:auto">页</button></form>';
  echo '</div>';
}

function node_api($method, $path, $data = null) {
  $url = rtrim(NODE_URL, '/') . '/' . ltrim($path, '/');
  $opts = ['http' => [
    'method' => $method,
    'header' => 'Authorization: Bearer ' . NODE_TOKEN . "\r\nContent-Type: application/json\r\n",
    'timeout' => 30,
  ]];
  if ($data !== null) $opts['http']['content'] = is_string($data) ? $data : json_encode($data);
  $ctx = stream_context_create($opts);
  $result = @file_get_contents($url, false, $ctx);
  if ($result === false) throw new Exception('Node API 请求失败: ' . $path);
  return json_decode($result, true);
}

function wait_node_job($jobId, $timeoutSeconds = 60) {
  if (!$jobId) return ['ok' => true];
  $deadline = time() + $timeoutSeconds;
  while (time() < $deadline) {
    $jobs = node_api('GET', '/api/jobs')['jobs'] ?? [];
    foreach ($jobs as $job) {
      if (strval($job['id'] ?? '') !== strval($jobId)) continue;
      $status = strval($job['status'] ?? '');
      if ($status === 'done') return ['ok' => true];
      if ($status === 'failed') throw new Exception(strval($job['error'] ?? '任务执行失败'));
      break;
    }
    usleep(500000);
  }
  throw new Exception('删除执行超时，请稍后刷新确认；系统不会提前显示删除成功');
}

function now() { return time(); }
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function has_role($role) { return ($_SESSION['role'] ?? '') === $role; }
function is_admin() { return has_role('admin'); }
function is_user() { return !empty($_SESSION['user_id']) && !is_admin(); }

// 会员是否有效（未到期且已激活）
function membership_expires_at() {
  if (is_admin()) return 0;
  $uid = intval($_SESSION['user_id'] ?? 0);
  if (!$uid) return 0;
  static $cache = [];
  if (!isset($cache[$uid])) {
    $stmt = db()->prepare('SELECT membership_expires_at FROM users WHERE id=?');
    $stmt->execute([$uid]);
    $cache[$uid] = intval($stmt->fetchColumn() ?: 0);
  }
  return $cache[$uid];
}
function membership_active() {
  if (is_admin()) return true;
  $exp = membership_expires_at();
  return $exp > 0 && $exp > now();
}

function log_operation($action, $targetType = '', $targetId = '', $details = '') {
  try {
    db()->prepare('INSERT INTO operation_logs(actor_user_id,actor_username,actor_role,action,target_type,target_id,details,created_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([
        intval($_SESSION['user_id'] ?? 0),
        strval($_SESSION['username'] ?? ''),
        strval($_SESSION['role'] ?? ''),
        strval($action),
        strval($targetType),
        strval($targetId),
        strval($details),
        now(),
      ]);
  } catch (Exception $e) {
  }
}
