<?php
require_once __DIR__ . '/../config.php';
$page = $_GET['page'] ?? 'dashboard';

if ($page === 'api_accounts') {
  header('Content-Type: application/json');
  try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
    $action = $_POST['action'] ?? '';
    if ($action !== 'create') throw new Exception('unknown action');
    $payload = ['uin' => $_POST['uin'] ?? '', 'node_id' => $_POST['node_id'] ?? ''];
    $res = node_api('POST', '/api/accounts', $payload);
    log_operation('create_account', 'account', strval($_POST['uin'] ?? ''), '后台创建账号');
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_qrcode') {
  header('Content-Type: application/json');
  try {
    echo json_encode(node_api('GET', '/api/accounts/' . urlencode($_GET['uin'] ?? '') . '/qrcode'));
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_refresh_qrcode') {
  header('Content-Type: application/json');
  try {
    $res = node_api('POST', '/api/accounts/' . urlencode($_POST['uin'] ?? '') . '/qrcode/refresh');
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_start_account') {
  header('Content-Type: application/json');
  try {
    $res = node_api('POST', '/api/accounts/' . urlencode($_POST['uin'] ?? '') . '/start');
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_restart_container') {
  header('Content-Type: application/json');
  try {
    $uin = trim($_POST['uin'] ?? '');
    $res = node_api('POST', '/api/accounts/' . urlencode($uin) . '/restart-container');
    if (!empty($res['ok']) && !empty($res['job_id'])) {
      db()->prepare("UPDATE accounts SET login_status='restarting',login_error='' WHERE uin=?")->execute([$uin]);
      echo json_encode(['ok' => true, 'job_id' => $res['job_id']]);
    } else {
      echo json_encode(['ok' => false, 'error' => $res['error'] ?? '容器重启任务创建失败']);
    }
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_recreate_account') {
  header('Content-Type: application/json');
  try {
    $res = node_api('POST', '/api/accounts/' . urlencode($_POST['uin'] ?? '') . '/recreate', ['password' => strval($_POST['password'] ?? '')]);
    echo json_encode(!empty($res['ok']) && !empty($res['job_id']) ? ['ok' => true, 'job_id' => $res['job_id']] : ['ok' => false, 'error' => $res['error'] ?? '重新创建任务提交失败']);
  } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

if ($page === 'api_delete_account') {
  header('Content-Type: application/json');
  try {
    $uin = '';
    $force = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $uin = trim($_POST['uin'] ?? '');
      $force = ($_POST['force'] ?? '') === '1';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
      parse_str(file_get_contents('php://input'), $data);
      $uin = trim($data['uin'] ?? '');
      $force = ($data['force'] ?? '') === '1';
    } else {
      throw new Exception('POST or DELETE required');
    }
    if ($uin === '') throw new Exception('缺少账号');
    $path = '/api/accounts/' . urlencode($uin) . ($force ? '?force=1' : '');
    $res = node_api('DELETE', $path);
    if (empty($res['ok'])) throw new Exception($res['error'] ?? '删除请求失败');
    $jobId = strval($res['job_id'] ?? '');
    cleanup_account_data($uin);
    log_operation($force ? 'force_delete_account' : 'delete_account', 'account', $uin, '后台提交删除账号');
    echo json_encode(['ok' => true, 'deleted' => !empty($res['deleted']), 'job_id' => $jobId]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_job_status') {
  header('Content-Type: application/json');
  try {
    $jobId = trim($_GET['job_id'] ?? '');
    if ($jobId === '') throw new Exception('缺少任务编号');
    $jobs = node_api('GET', '/api/jobs')['jobs'] ?? [];
    foreach ($jobs as $job) {
      if (strval($job['id'] ?? '') !== $jobId) continue;
      echo json_encode(['ok' => true, 'status' => $job['status'] ?? '', 'error' => $job['error'] ?? '']);
      exit;
    }
    throw new Exception('任务不存在或已完成清理');
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_sync_status') {
  header('Content-Type: application/json');
  try {
    if (empty($_SESSION['user_id']) || !is_admin()) throw new Exception('无权访问');
    $uin = trim($_GET['uin'] ?? '');
    $action = strval($_GET['action'] ?? '');
    if ($uin === '' || !in_array($action, ['refresh_friends', 'refresh_groups', 'refresh_members'], true)) throw new Exception('同步参数错误');
    if ($action === 'refresh_members') {
      $groupId = preg_replace('/\D/', '', strval($_GET['group_id'] ?? ''));
      if ($groupId === '') throw new Exception('缺少群号');
      $stmt = db()->prepare("SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action='refresh_members' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.group_id'))=? ORDER BY created_at DESC LIMIT 1");
      $stmt->execute([$uin, $groupId]);
    } else {
      $stmt = db()->prepare('SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action=? ORDER BY created_at DESC LIMIT 1');
      $stmt->execute([$uin, $action]);
    }
    echo json_encode(['ok' => true, 'job' => $stmt->fetch() ?: null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_ticket') {
  header('Content-Type: application/json');
  try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
    $res = node_api('POST', '/api/accounts/' . urlencode($_POST['uin'] ?? '') . '/ticket', [
      'ticket' => $_POST['ticket'] ?? '',
      'randstr' => $_POST['randstr'] ?? '',
      'sid' => $_POST['sid'] ?? '',
    ]);
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_new_device') {
  header('Content-Type: application/json');
  try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
    $res = node_api('POST', '/api/accounts/' . urlencode($_POST['uin'] ?? '') . '/new-device', []);
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? null]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_export_content') {
  header('Content-Type: application/json');
  try {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('导出记录不存在');
    $res = node_api('GET', '/api/exports/' . $id);
    echo json_encode(['ok' => true, 'content' => $res['content'] ?? '', 'line_count' => intval($res['line_count'] ?? 0)]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'api_send') {
  header('Content-Type: application/json');
  try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
    $res = node_api('POST', '/api/mail/jobs', [
      'config_id' => $_POST['config_id'] ?? '',
      'export_record_id' => intval($_POST['export_id'] ?? 0),
      'recipients' => $_POST['recipients'] ?? '',
      'subject' => $_POST['subject'] ?? 'QQ号列表',
      'text_content' => $_POST['content'] ?? '',
      'html_content' => $_POST['html'] ?? '',
      'created_by' => $_SESSION['username'] ?? 'admin_root',
      'owner_user_id' => intval($_SESSION['user_id'] ?? 0),
      'owner_role' => 'admin',
    ]);
    echo json_encode(['ok' => true, 'job_id' => $res['job_id'] ?? '', 'recipient_count' => intval($res['recipient_count'] ?? 0)]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($page === 'login') { require __DIR__ . '/login.php'; exit; }
if ($page === 'logout') { session_destroy(); header('Location: /akalullusmtpy/?page=login'); exit; }

if (empty($_SESSION['user_id'])) {
  require __DIR__ . '/login.php';
  exit;
}
if (!is_admin()) {
  http_response_code(403);
  echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>无权限</title><link rel="icon" type="image/svg+xml" href="../images/yun-mail.svg"><link rel="stylesheet" href="../css/style.css?v=28"></head><body><div class="login-wrap"><div class="login-box"><img class="login-brand-icon" src="../images/yun-mail.svg" alt=""><h1>无权访问后台</h1><p style="color:#ff7a7a">当前登录账号（' . e($_SESSION['display_name'] ?? $_SESSION['username'] ?? '') . '）不是管理员，无法进入后台。</p><p style="margin-top:16px"><a class="btn" href="?page=logout" style="width:100%;box-sizing:border-box;text-align:center">退出并重新用管理员账号登录</a></p></div></div></body></html>';
  exit;
}

if ($page === 'account_groups') {
  require __DIR__ . '/../pages/account_groups.php';
  exit;
}

$nodePages = ['qq_nodes', 'mail_nodes'];
$navItems = [
  ['key' => 'dashboard', 'label' => '概览', 'href' => '?page=dashboard'],
  ['key' => 'nodes', 'label' => '节点', 'children' => [
    ['key' => 'qq_nodes', 'label' => 'QQ 节点', 'href' => '?page=qq_nodes'],
    ['key' => 'mail_nodes', 'label' => '邮件节点', 'href' => '?page=mail_nodes'],
  ]],
  ['key' => 'users', 'label' => '用户管理', 'href' => '?page=users'],
  ['key' => 'cards', 'label' => '卡密管理', 'href' => '?page=cards'],
  ['key' => 'operation_logs', 'label' => '操作日志', 'href' => '?page=operation_logs'],
  ['key' => 'accounts', 'label' => '账号', 'href' => '?page=accounts'],
  ['key' => 'exports', 'label' => '导出记录', 'href' => '?page=exports'],
  ['key' => 'smtp', 'label' => 'SMTP', 'href' => '?page=smtp'],
  ['key' => 'send', 'label' => '群发邮件', 'href' => '?page=send'],
  ['key' => 'smtp_logs', 'label' => '发信记录', 'href' => '?page=smtp_logs'],
  ['key' => 'site_settings', 'label' => '站点设置', 'href' => '?page=site_settings'],
  ['key' => 'notices', 'label' => '平台公告', 'href' => '?page=notices'],
  ['key' => 'password', 'label' => '修改密码', 'href' => '?page=password'],
];
$allowed = ['dashboard','qq_nodes','mail_nodes','users','cards','operation_logs','accounts','exports','smtp','send','smtp_logs','site_settings','notices','password'];
$file = __DIR__ . '/../pages/' . basename($page) . '.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YUN信 - 管理后台</title>
<link rel="icon" type="image/svg+xml" href="../images/yun-mail.svg">
<link rel="stylesheet" href="../css/style.css?v=29">
</head>
<body>
<div class="top">
  <button class="menu-toggle" type="button" onclick="toggleSidebar()" aria-label="打开菜单"><i></i><i></i><i></i></button>
  <h1 class="brand-title"><img src="../images/yun-mail.svg" alt="">YUN信管理后台</h1>
  <div class="user">
    <span><?=e($current_display_name)?></span>
    <a href="?page=logout">退出</a>
  </div>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebarNav">
  <div class="sidebar-head">
    <strong>后台菜单</strong>
    <button class="sidebar-close" type="button" onclick="closeSidebar()">关闭</button>
  </div>
  <nav class="sidebar-links">
    <?php foreach ($navItems as $item):
      $key = $item['key'];
      $label = $item['label'];
      $hasChildren = !empty($item['children']);
      $active = $key === 'accounts' ? strpos($page, 'account') === 0 : ($page === $key || ($hasChildren && in_array($page, $nodePages, true)));
    ?>
    <?php if ($hasChildren): ?>
    <button type="button" class="sidebar-group-toggle <?=$active?'active':''?>" onclick="toggleSidebarGroup('nodesGroup')"><?=e($label)?></button>
    <div class="sidebar-submenu <?=in_array($page, $nodePages, true)?'show':''?>" id="nodesGroup">
      <?php foreach ($item['children'] as $child): ?>
      <a href="<?=e($child['href'])?>" class="<?=$page===$child['key']?'active':''?>" onclick="closeSidebar()"><?=e($child['label'])?></a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <a href="<?=e($item['href'])?>" class="<?=$active?'active':''?>" onclick="closeSidebar()"><?=e($label)?></a>
    <?php endif; ?>
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
function toggleSidebarGroup(id){document.getElementById(id).classList.toggle('show')}
</script>
</body>
</html>
