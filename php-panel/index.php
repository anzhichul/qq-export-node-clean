<?php
require_once __DIR__ . '/config.php';
$page = $_GET['page'] ?? 'user_home';

if ($page === 'login') { require __DIR__ . '/login.php'; exit; }
if ($page === 'register') { require __DIR__ . '/pages/register.php'; exit; }
if ($page === 'logout') { session_destroy(); header('Location: /?page=login'); exit; }

require __DIR__ . '/includes/auth.php';

// JSON API 端点（在 HTML 布局之前）
if (in_array($page, ['api_user_qrcode', 'api_user_restart_container', 'api_user_refresh_qrcode', 'api_user_recreate_account', 'api_user_delete_account', 'api_user_job_status', 'api_user_sync_status'], true)) {
  header('Content-Type: application/json');
  try {
    $uin = trim(in_array($page, ['api_user_qrcode', 'api_user_job_status', 'api_user_sync_status'], true) ? ($_GET['uin'] ?? '') : ($_POST['uin'] ?? ''));
    if ($uin === '') throw new Exception('缺少账号');
    if ($page === 'api_user_sync_status') {
      $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
      $owner->execute([intval($_SESSION['user_id'] ?? 0), $uin]);
      if (!$owner->fetch()) throw new Exception('该账号不属于当前用户');
      $action = strval($_GET['action'] ?? '');
      if (!in_array($action, ['refresh_friends', 'refresh_groups', 'refresh_members'], true)) throw new Exception('同步类型错误');
      if ($action === 'refresh_members') {
        $groupId = preg_replace('/\D/', '', strval($_GET['group_id'] ?? ''));
        if ($groupId === '') throw new Exception('缺少群号');
        $stmt = db()->prepare("SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action='refresh_members' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.group_id'))=? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$uin, $groupId]);
      } else {
        $stmt = db()->prepare('SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action=? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$uin, $action]);
      }
      $job = $stmt->fetch() ?: null;
      echo json_encode(['ok' => true, 'job' => $job]);
      exit;
    }
    if ($page === 'api_user_job_status') {
      $jobId = trim($_GET['job_id'] ?? '');
      if ($jobId === '') throw new Exception('缺少任务编号');
      if (strval($_SESSION['account_delete_jobs'][$jobId] ?? '') !== $uin) throw new Exception('无权查看该任务');
      $jobs = node_api('GET', '/api/jobs')['jobs'] ?? [];
      foreach ($jobs as $job) {
        if (strval($job['id'] ?? '') !== $jobId || strval($job['account_uin'] ?? '') !== $uin) continue;
        if (in_array(strval($job['status'] ?? ''), ['done', 'failed'], true)) unset($_SESSION['account_delete_jobs'][$jobId]);
        echo json_encode(['ok' => true, 'status' => $job['status'] ?? '', 'error' => $job['error'] ?? '']);
        exit;
      }
      throw new Exception('任务不存在或已完成清理');
    }
    $owner = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=? LIMIT 1');
    $owner->execute([intval($_SESSION['user_id'] ?? 0), $uin]);
    if (!$owner->fetch()) throw new Exception('该账号不属于当前用户');
    if ($page === 'api_user_qrcode') {
      echo json_encode(node_api('GET', '/api/accounts/' . urlencode($uin) . '/qrcode'));
    } elseif ($page === 'api_user_delete_account') {
      $res = node_api('DELETE', '/api/accounts/' . urlencode($uin) . '?force=1');
      $jobId = strval($res['job_id'] ?? '');
      if ($jobId !== '') $_SESSION['account_delete_jobs'][$jobId] = $uin;
      cleanup_account_data($uin);
      echo json_encode(['ok' => true, 'deleted' => !empty($res['deleted']), 'job_id' => $jobId]);
    } elseif ($page === 'api_user_restart_container') {
      $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/restart-container', '{}');
      if (!empty($res['ok']) && !empty($res['job_id'])) {
        db()->prepare("UPDATE accounts SET login_status='restarting',login_error='' WHERE uin=?")->execute([$uin]);
      }
      echo json_encode(!empty($res['ok']) && !empty($res['job_id']) ? ['ok' => true, 'job_id' => $res['job_id']] : ['ok' => false, 'error' => $res['error'] ?? '容器重启任务创建失败']);
    } elseif ($page === 'api_user_recreate_account') {
      $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/recreate', []);
      echo json_encode(!empty($res['ok']) && !empty($res['job_id']) ? ['ok' => true, 'job_id' => $res['job_id']] : ['ok' => false, 'error' => $res['error'] ?? '重新创建任务提交失败']);
    } else {
      $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/qrcode/refresh', '{}');
      echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
    }
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if (in_array($page, ['api_export_content', 'api_export_download', 'api_export_delete'], true)) {
  $id = intval(($page === 'api_export_delete' ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0)));
  $record = null;
  try {
    if ($id <= 0) throw new Exception('导出记录不存在');
    $stmt = db()->prepare('SELECT er.* FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE er.id=? AND ua.user_id=? LIMIT 1');
    $stmt->execute([$id, intval($_SESSION['user_id'] ?? 0)]);
    $record = $stmt->fetch();
    if (!$record) throw new Exception('导出记录不存在');
  } catch (Exception $e) {
    if ($page === 'api_export_download') {
      http_response_code(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo '导出文件不存在';
    } else {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
  }

  if ($page === 'api_export_download') {
    try {
      $exportData = node_api('GET', '/api/exports/' . $id);
      $content = strval($exportData['content'] ?? '');
    } catch (Exception $e) {
      http_response_code(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo '导出文件不存在';
      exit;
    }
    $type = preg_replace('/[^a-z0-9_-]/i', '', strval($record['export_type'] ?? 'export')) ?: 'export';
    $group = preg_replace('/\D/', '', strval($record['group_id'] ?? ''));
    $filename = 'qq_' . preg_replace('/\D/', '', strval($record['account_uin'])) . '_' . $type . ($group ? '_' . $group : '') . '_' . date('Ymd_His', intval($record['created_at'])) . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
  }

  if ($page === 'api_export_delete') {
    header('Content-Type: application/json');
    try {
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('请求方式错误');
      $csrf = strval($_POST['csrf_token'] ?? '');
      if (!$csrf || !hash_equals(strval($_SESSION['export_csrf'] ?? ''), $csrf)) throw new Exception('请求已失效，请刷新页面重试');
      node_api('DELETE', '/api/exports/' . $id);
      echo json_encode(['ok' => true]);
    } catch (Exception $e) {
      echo json_encode(['ok' => false, 'error' => '导出文件删除失败，请稍后重试']);
    }
    exit;
  }

  header('Content-Type: application/json');
  try {
    $exportData = node_api('GET', '/api/exports/' . $id);
    $content = strval($exportData['content'] ?? '');
    echo json_encode(['ok' => true, 'content' => strval($content), 'line_count' => intval($record['line_count'] ?? 0)]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_task_stats') {
  header('Content-Type: application/json');
  try {
    if (function_exists('expire_overtime_tasks')) expire_overtime_tasks();
    db()->prepare("UPDATE mail_jobs SET status='cancelled',error='30分钟未执行，任务已自动取消',finished_at=? WHERE status='pending' AND task_key IS NOT NULL AND created_at<?")
      ->execute([time(), time() - 1800]);
    $jobs = [];
    $stmt = db()->prepare("SELECT m.id,m.subject,m.recipient_count,m.sent_count,m.failed_count,m.status,m.created_at,m.task_key,m.task_key_expires_at,m.execution_deadline,
      (SELECT COUNT(*) FROM mail_job_recipients r WHERE r.job_id=m.id AND r.status IN ('pending','running')) AS remaining
      FROM mail_jobs m WHERE m.created_by=? ORDER BY m.created_at DESC LIMIT 50");
    $stmt->execute([$_SESSION['username']]);
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
    echo json_encode(['ok' => true, 'jobs' => $jobs]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if (is_admin()) {
  header('Location: /akalullusmtpy/?page=dashboard');
  exit;
}

// 会员制：未激活/已过期用户只能访问激活页
$activated = membership_active();
if (!$activated && $page !== 'activate') {
  $page = 'activate';
}

$navItems = $activated ? [
  ['key' => 'user_home', 'label' => '个人中心', 'href' => '/?page=user_home'],
  ['key' => 'user_accounts', 'aliases' => ['user_account_detail'], 'label' => '我的账号', 'href' => '/?page=user_accounts'],
  ['key' => 'user_add_account', 'label' => '添加账号', 'href' => '/?page=user_add_account'],
  ['key' => 'user_exports', 'label' => '我的导出', 'href' => '/?page=user_exports'],
  ['key' => 'user_smtp', 'label' => '我的 SMTP', 'href' => '/?page=user_smtp'],
  ['key' => 'user_send', 'label' => '我的发信', 'href' => '/?page=user_send'],
  ['key' => 'user_devices', 'label' => '我的设备', 'href' => '/?page=user_devices'],
  ['key' => 'user_recharge', 'label' => '充值中心', 'href' => '/?page=user_recharge'],
  ['key' => 'password', 'label' => '修改密码', 'href' => '/?page=password'],
] : [
  ['key' => 'activate', 'label' => '激活会员', 'href' => '/?page=activate'],
  ['key' => 'password', 'label' => '修改密码', 'href' => '/?page=password'],
];
$allowed = ['user_home','user_accounts','user_account_detail','user_add_account','user_exports','user_smtp','user_send','user_devices','user_recharge','password','activate'];
$file = __DIR__ . '/pages/' . basename($page) . '.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YUN信 - 用户中心</title>
<link rel="icon" type="image/svg+xml" href="images/yun-mail.svg">
<link rel="stylesheet" href="css/style.css?v=30">
</head>
<body>
<div class="top">
  <button class="menu-toggle" type="button" onclick="toggleSidebar()" aria-label="打开菜单"><i></i><i></i><i></i></button>
  <h1 class="brand-title"><img src="images/yun-mail.svg" alt="">YUN信</h1>
  <div class="user">
    <span><?=e($current_display_name)?></span>
    <a href="?page=logout">退出</a>
  </div>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebarNav">
  <div class="sidebar-head">
    <strong>YUN信</strong>
    <button class="sidebar-close" type="button" onclick="closeSidebar()">关闭</button>
  </div>
  <nav class="sidebar-links">
    <?php foreach ($navItems as $item): $isNavActive = $page === $item['key'] || in_array($page, $item['aliases'] ?? [], true); ?>
    <a href="<?=e($item['href'])?>" class="<?=$isNavActive?'active':''?>" onclick="closeSidebar()"><?=e($item['label'])?></a>
    <?php endforeach; ?>
  </nav>
</aside>
<div class="layout"><div class="main">
<?php
if (in_array($page, $allowed) && file_exists($file)) {
  require $file;
} else {
  echo '<div class="empty">页面不存在</div>';
}
?>
</div></div>
<div id="toast" class="toast"></div>
<script>
function toast(msg){var t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(function(){t.classList.remove('show')},2000)}
function toggleSidebar(){document.getElementById('sidebarNav').classList.toggle('show');document.getElementById('sidebarBackdrop').classList.toggle('show')}
function closeSidebar(){document.getElementById('sidebarNav').classList.remove('show');document.getElementById('sidebarBackdrop').classList.remove('show')}
</script>
</body>
</html>
