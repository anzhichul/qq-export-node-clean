<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

function clean_display_name($value, $fallback = '-') {
  $text = strval($value ?? '');
  $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text);
  $text = preg_replace('/^[' . "\p{Z}\p{C}" . ']+|[' . "\p{Z}\p{C}" . ']+$/u', '', $text);
  return $text === '' ? $fallback : $text;
}

function user_owned_status_label($row) {
  $status = strval($row['login_status'] ?? 'offline');
  if (!empty($row['online'])) return '在线';
  if ($status === 'creating') return '创建中';
  if ($status === 'restarting') return '重启中';
  if ($status === 'waiting_scan') return '等待手机确认';
  if ($status === 'waiting_qrcode') return '等待二维码';
  if ($status === 'idle_offline') return '离线';
  if ($status === 'create_failed') return '创建失败';
  return $status ?: '离线';
}

$error = $error ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    $uin = trim($_POST['uin'] ?? '');
    if ($uin === '') throw new Exception('缺少账号');
    $check = db()->prepare('SELECT id FROM user_accounts WHERE user_id=? AND account_uin=?');
    $check->execute([$_SESSION['user_id'], $uin]);
    if (!$check->fetch()) throw new Exception('该账号不属于当前用户');

    if ($_POST['action'] === 'user_delete_account') {
      $res = node_api('DELETE', '/api/accounts/' . urlencode($uin) . '?force=1');
      if (empty($res['ok'])) throw new Exception($res['error'] ?? '删除请求失败');
      session_write_close();
      wait_node_job($res['job_id'] ?? null);
      echo '<script>alert("账号和相关数据已彻底删除");location.href="/?page=user_accounts";</script>'; exit;
    }
    if ($_POST['action'] === 'user_refresh_friends') {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/friends/refresh', '{}');
      echo '<script>alert("已提交好友数量刷新任务");location.href="user_accounts.php";</script>'; exit;
    }
    if ($_POST['action'] === 'user_refresh_groups') {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/refresh', '{}');
      echo '<script>alert("已提交群数量刷新任务");location.href="user_accounts.php";</script>'; exit;
    }
    if ($_POST['action'] === 'user_export_friends') {
      $res = node_api('GET', '/api/accounts/' . urlencode($uin) . '/friends/export');
      echo '<script>alert("已导出 ' . intval($res['count'] ?? 0) . ' 个好友");location.href="user_accounts.php";</script>'; exit;
    }
  } catch (Exception $e) { $error = $e->getMessage(); }
}

try {
  $perPage = 20;
  $pageNum = max(1, intval($_GET['p'] ?? 1));
  $countStmt = db()->prepare('SELECT COUNT(*) FROM user_accounts WHERE user_id=?');
  $countStmt->execute([$_SESSION['user_id']]);
  $total = intval($countStmt->fetchColumn());
  $totalPages = max(1, intval(ceil($total / $perPage)));
  if ($pageNum > $totalPages) $pageNum = $totalPages;
  $offset = ($pageNum - 1) * $perPage;
  $stmt = db()->prepare('SELECT ua.account_uin,ua.token AS account_token,ua.display_name AS owned_display_name,ua.created_at AS bind_created_at,a.uin,a.nickname,a.node_id,a.login_status,a.login_error,a.online,a.last_seen,a.qr_image,a.qr_updated_at,n.name AS node_name,(SELECT COUNT(*) FROM friends f WHERE f.account_uin=ua.account_uin) AS friend_count,(SELECT COUNT(*) FROM groups_data g WHERE g.account_uin=ua.account_uin) AS group_count FROM user_accounts ua LEFT JOIN accounts a ON a.uin=ua.account_uin LEFT JOIN nodes n ON n.node_id=a.node_id WHERE ua.user_id=? ORDER BY ua.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset));
  $stmt->execute([$_SESSION['user_id']]);
  $accounts = $stmt->fetchAll();
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">账号数据读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }
?>
<h2 class="page-title">我的账号</h2>
<?php if ($error): ?><div class="card"><p style="color:#c73d3d"><?=e($error)?></p></div><?php endif; ?>

<?php if ($accounts): ?>
<?php foreach ($accounts as $a):
  $uin = strval($a['account_uin'] ?? '');
  if ($uin === '') $uin = strval($a['uin'] ?? '');
  $acctToken = strval($a['account_token'] ?? '');
  $hasNodeAccount = $a['uin'] !== null;
  $nickname = clean_display_name($a['nickname'] ?? ($a['owned_display_name'] ?? ''), $uin ?: '-');
  $isOnline = !empty($a['online']) && intval($a['last_seen'] ?? 0) >= (time() - 45);
?>
<div class="card account-list-card">
  <div class="account-list-summary">
    <img class="account-detail-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($uin)?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($uin)?>/<?=urlencode($uin)?>/100" onerror="avatarFallback(this)" alt="">
    <div class="account-list-identity">
      <strong><?=e($nickname)?></strong>
      <span>QQ：<?=e($uin)?></span>
    </div>
    <span class="badge <?=$isOnline?'on':'off'?>"><?=$isOnline?'在线':($hasNodeAccount?e(user_owned_status_label($a)):'<span class="spin"></span>创建中')?></span>
    <div class="account-list-actions">
      <form method="post" onsubmit="return submitCleanDelete(this)"><input type="hidden" name="uin" value="<?=e($uin)?>"><input type="hidden" name="action" value="user_delete_account"><button type="submit" class="btn btn-sm btn-gray">删除</button></form>
      <a href="/?page=user_account_detail&token=<?=urlencode($acctToken)?>" class="btn btn-sm">进入管理</a>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php render_pager($pageNum, $totalPages, ['page' => 'user_accounts']); ?>
<?php else: ?>
<div class="card"><p class="empty">你还没有绑定账号，先去“添加账号”创建一个。</p></div>
<?php endif; ?>
<script>
function submitCleanDelete(form) {
  if (!confirm('确定彻底删除这个 QQ 账号吗？系统会停止本机容器、删除独立运行目录，并清理账号绑定、好友、群、成员和导出数据。')) return false;
  var button = form.querySelector('button'); button.disabled = true; button.textContent = '正在彻底删除...';
  var uin = form.querySelector('[name="uin"]').value;
  fetch('/?page=api_user_delete_account', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'uin=' + encodeURIComponent(uin)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) throw new Error(data.error || '删除失败');
    if (data.deleted || !data.job_id) { location.href='/?page=user_accounts'; return; }
    pollUserDelete(data.job_id, uin, button);
  }).catch(function(error){button.disabled=false;button.textContent='删除';toast(error.message||'删除请求失败')});
  return false;
}
function pollUserDelete(jobId, uin, button) {
  fetch('/?page=api_user_job_status&uin='+encodeURIComponent(uin)+'&job_id='+encodeURIComponent(jobId)+'&t='+Date.now())
    .then(function(r){return r.json()}).then(function(data){
      if (!data.ok) throw new Error(data.error || '查询任务失败');
      if (data.status === 'done') { button.textContent='删除完成'; location.href='/?page=user_accounts'; return; }
      if (data.status === 'failed') throw new Error(data.error || '删除失败');
      button.textContent='正在处理...'; setTimeout(function(){pollUserDelete(jobId,uin,button)},1500);
    }).catch(function(error){button.disabled=false;button.textContent='删除';toast(error.message||'查询删除状态失败')});
}
function avatarFallback(image) {
  var fallback = image.getAttribute('data-fallback');
  if (fallback) { image.removeAttribute('data-fallback'); image.src = fallback; return; }
  image.onerror = null; image.classList.add('avatar-missing'); image.removeAttribute('src');
}
</script>
