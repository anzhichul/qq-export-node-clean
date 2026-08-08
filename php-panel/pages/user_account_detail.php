<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

function clean_display_name($value, $fallback = '-') {
  $text = strval($value ?? '');
  $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text);
  $text = preg_replace('/^[' . "\p{Z}\p{C}" . ']+|[' . "\p{Z}\p{C}" . ']+$/u', '', $text);
  return $text === '' ? $fallback : $text;
}

function page_number($key) {
  $value = intval($_GET[$key] ?? 1);
  return $value > 0 ? $value : 1;
}

function latest_account_sync_job($uin, $action, $groupId = '') {
  if ($action === 'refresh_members' && $groupId !== '') {
    $stmt = db()->prepare("SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action='refresh_members' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.group_id'))=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$uin, $groupId]);
  } else {
    $stmt = db()->prepare('SELECT id,status,error,created_at,started_at,finished_at FROM jobs WHERE account_uin=? AND action=? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$uin, $action]);
  }
  return $stmt->fetch() ?: null;
}

$token = trim($_GET['token'] ?? '');
$tab = $_GET['tab'] ?? 'friends';
$error = $error ?? '';
if ($token === '') { echo '<div class="empty">缺少账号参数</div>'; return; }

try {
  $ownerStmt = db()->prepare('SELECT * FROM user_accounts WHERE user_id=? AND token=? LIMIT 1');
  $ownerStmt->execute([$_SESSION['user_id'], $token]);
  $owner = $ownerStmt->fetch();
  if (!$owner) throw new Exception('该账号不属于当前用户');
  $uin = strval($owner['account_uin'] ?? '');

  if ($tab === 'members' && !empty($_GET['gid']) && ($_GET['sync'] ?? '') === '1') {
    $groupId = preg_replace('/\D/', '', strval($_GET['gid']));
    if ($groupId === '') throw new Exception('群号格式错误');
    $activeJob = latest_account_sync_job($uin, 'refresh_members', $groupId);
    if (!in_array(strval($activeJob['status'] ?? ''), ['pending', 'running'], true)) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/members/refresh', '{}');
    }
    echo '<script>location.replace(' . json_encode('/?page=user_account_detail&token=' . urlencode($token) . '&tab=members&gid=' . urlencode($groupId)) . ')</script>'; exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'refresh_friends') {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/friends/refresh', '{}');
      echo '<script>location.replace(' . json_encode('/?page=user_account_detail&token=' . urlencode($token) . '&tab=friends') . ')</script>'; exit;
    }
    if ($_POST['action'] === 'refresh_groups') {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/refresh', '{}');
      echo '<script>location.replace(' . json_encode('/?page=user_account_detail&token=' . urlencode($token) . '&tab=groups') . ')</script>'; exit;
    }
    if ($_POST['action'] === 'refresh_members' && !empty($_POST['group_id'])) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($_POST['group_id']) . '/members/refresh', '{}');
      echo '<script>location.replace(' . json_encode('/?page=user_account_detail&token=' . urlencode($token) . '&tab=members&gid=' . urlencode($_POST['group_id'])) . ')</script>'; exit;
    }
    if ($_POST['action'] === 'export_friends') {
      $res = node_api('GET', '/api/accounts/' . urlencode($uin) . '/friends/export');
      echo '<script>alert("已导出 ' . intval($res['count'] ?? 0) . ' 个好友");location.href="?page=user_account_detail&token=' . urlencode($token) . '&tab=friends";</script>'; exit;
    }
    if ($_POST['action'] === 'export_members' && !empty($_POST['group_id'])) {
      $groupId = strval($_POST['group_id']);
      $excludeManagement = !empty($_POST['exclude_management']);
      $job = node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/members/refresh', '{}');
      $jobId = strval($job['job_id'] ?? '');
      if (!$jobId) throw new Exception('同步任务创建失败');
      $done = false; $failed = '';
      for ($i = 0; $i < 25; $i++) {
        usleep(1000 * 1000);
        $jobs = node_api('GET', '/api/jobs')['jobs'] ?? [];
        foreach ($jobs as $item) {
          if (($item['id'] ?? '') !== $jobId) continue;
          if (($item['status'] ?? '') === 'done') $done = true;
          elseif (($item['status'] ?? '') === 'failed') $failed = strval($item['error'] ?? '同步失败');
          break;
        }
        if ($done || $failed !== '') break;
      }
      if ($failed !== '') throw new Exception($failed);
      if (!$done) throw new Exception('同步群成员超时，请稍后重试');
      $res = node_api('GET', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/export/members' . ($excludeManagement ? '?exclude_management=1' : ''));
      echo '<script>alert("已导出 ' . intval($res['count'] ?? 0) . ' 个成员' . ($excludeManagement ? '（已排除群主和管理员）' : '') . '");location.href="?page=user_account_detail&token=' . urlencode($token) . '&tab=members&gid=' . urlencode($groupId) . '";</script>'; exit;
    }
  }

  $accountStmt = db()->prepare('SELECT a.*,n.name AS node_name FROM accounts a LEFT JOIN nodes n ON n.node_id=a.node_id WHERE a.uin=? LIMIT 1');
  $accountStmt->execute([$uin]);
  $account = $accountStmt->fetch();
  $isOnline = $account && !empty($account['online']) && intval($account['last_seen'] ?? 0) >= (time() - 45);
  $createFailed = ($account['login_status'] ?? '') === 'create_failed';
  $nickname = clean_display_name($account['nickname'] ?? ($owner['display_name'] ?? ''), $uin);
  $friendsSyncJob = latest_account_sync_job($uin, 'refresh_friends');
  $groupsSyncJob = latest_account_sync_job($uin, 'refresh_groups');
  $memberGroupId = $tab === 'members' ? preg_replace('/\D/', '', strval($_GET['gid'] ?? '')) : '';
  $membersSyncJob = $memberGroupId !== '' ? latest_account_sync_job($uin, 'refresh_members', $memberGroupId) : null;
} catch (Exception $e) {
  echo '<div class="card"><p style="color:#c73d3d">' . e($e->getMessage()) . '</p></div>';
  return;
}
?>
<h2 class="page-title">账号详情</h2>
<div class="card">
  <div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px">
    <div class="account-detail-identity">
      <img class="account-detail-avatar" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($uin)?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($uin)?>/<?=urlencode($uin)?>/100" onerror="avatarFallback(this)" alt="">
      <div>
      <h3 style="margin:0"><?=e($nickname)?></h3>
      <div style="color:#6d7c8d;font-size:13px;margin-top:6px">QQ：<?=e($uin)?></div>
      <div style="color:#6d7c8d;font-size:13px">节点：<?=e($account['node_name'] ?? '-')?></div>
      </div>
    </div>
    <span class="badge <?=$isOnline?'on':'off'?>" id="acctStatus"><?=$isOnline?'在线':($createFailed?'创建失败':(($account['login_status'] ?? '')==='restarting'?'<span class="spin"></span>重启中':(($account['login_status'] ?? '')==='creating'?'<span class="spin"></span>创建中':'离线')))?></span>
  </div>
  <div class="account-manage-actions">
    <a href="/?page=user_accounts" class="btn btn-sm btn-gray">返回账号列表</a>
    <?php if ($createFailed): ?><button type="button" class="btn btn-sm" onclick="recreateUserAccount()">重新创建</button><?php else: ?><button type="button" class="btn btn-sm" onclick="restartUserContainer()">重启容器</button><?php endif; ?>
    <?php if (!$isOnline && !$createFailed): ?><button type="button" class="btn btn-sm btn-gray" onclick="openUserQrModal()">查看二维码</button><?php endif; ?>
  </div>
  <?php if ($createFailed): ?><div style="margin-top:12px;padding:10px 12px;border-radius:9px;background:#fff4f4;color:#b83232;font-size:12px">账号未创建成功：<?=e($account['login_error'] ?? '请删除后重新添加')?></div><?php endif; ?>
</div>

<div class="modal" id="userQrModal">
  <div class="modal-box qr-modal-box">
    <h2>QQ 登录二维码</h2>
    <p id="userQrStatus">正在获取二维码...</p>
    <div class="qr-modal-image"><img id="userQrImage" alt="QQ 登录二维码"><span id="userQrLoading">加载中...</span></div>
    <div class="qr-modal-actions">
      <button type="button" class="btn btn-sm" onclick="refreshUserQrCode()">刷新二维码</button>
      <button type="button" class="btn btn-sm btn-gray" onclick="closeUserQrModal()">关闭</button>
    </div>
  </div>
</div>

<div class="card">
  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <a href="?page=user_account_detail&token=<?=urlencode($token)?>&tab=friends" class="btn btn-sm <?=$tab==='friends'?'':'btn-gray'?>">好友</a>
    <a href="?page=user_account_detail&token=<?=urlencode($token)?>&tab=groups" class="btn btn-sm <?=$tab==='groups'?'':'btn-gray'?>">群</a>
  </div>

<?php if ($tab === 'friends'): ?>
  <?php
  $friendsPage = page_number('p');
  $perPage = 20;
  $countStmt = db()->prepare('SELECT COUNT(*) FROM friends WHERE account_uin=?');
  $countStmt->execute([$uin]);
  $total = intval($countStmt->fetchColumn());
  $pages = max(1, intval(ceil($total / $perPage)));
  if ($friendsPage > $pages) $friendsPage = $pages;
  $offset = ($friendsPage - 1) * $perPage;
  $stmt = db()->prepare('SELECT * FROM friends WHERE account_uin=? ORDER BY remark,nickname,user_id LIMIT ? OFFSET ?');
  $stmt->bindValue(1, $uin, PDO::PARAM_STR);
  $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(3, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <form method="post" style="display:inline" onsubmit="showSyncPanel('friends')"><input type="hidden" name="action" value="refresh_friends"><button type="submit" class="btn btn-sm" <?=in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>同步好友</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="action" value="export_friends"><button type="submit" class="btn btn-sm btn-gray">导出好友</button></form>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0">共 <?=$total?> 个好友</span>
  </div>
  <div id="friendsListBox">
  <?php if (in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在同步好友列表</strong><p>节点正在拉取好友数据，数据量较大时需要一些时间。刷新页面也不会中断。</p></div></div>
  <?php elseif ($rows): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:720px"><thead><tr><th class="avatar-column">头像</th><th>QQ 号</th><th>昵称</th><th>备注</th></tr></thead><tbody><?php foreach ($rows as $f): $friendUin = strval($f['user_id']); ?><tr><td class="avatar-cell"><img class="list-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($friendUin)?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($friendUin)?>/<?=urlencode($friendUin)?>/100" onerror="avatarFallback(this)" alt=""></td><td><?=e($friendUin)?></td><td><?=e(clean_display_name($f['nickname'] ?? '', '-'))?></td><td><?=e(clean_display_name($f['remark'] ?? '', '-'))?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pager($friendsPage, $pages, ['page' => 'user_account_detail', 'token' => $token, 'tab' => 'friends']); ?>
  <?php else: ?><p class="empty">暂无好友数据</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'groups'): ?>
  <?php
  $groupsPage = page_number('p');
  $perPage = 20;
  $countStmt = db()->prepare('SELECT COUNT(*) FROM groups_data WHERE account_uin=?');
  $countStmt->execute([$uin]);
  $total = intval($countStmt->fetchColumn());
  $pages = max(1, intval(ceil($total / $perPage)));
  if ($groupsPage > $pages) $groupsPage = $pages;
  $offset = ($groupsPage - 1) * $perPage;
  $stmt = db()->prepare('SELECT * FROM groups_data WHERE account_uin=? ORDER BY group_name,group_id LIMIT ? OFFSET ?');
  $stmt->bindValue(1, $uin, PDO::PARAM_STR);
  $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(3, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <form method="post" style="display:inline" onsubmit="showSyncPanel('groups')"><input type="hidden" name="action" value="refresh_groups"><button type="submit" class="btn btn-sm" <?=in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>同步群列表</button></form>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0">共 <?=$total?> 个群</span>
  </div>
  <div id="groupsListBox">
  <?php if (in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在同步群列表</strong><p>节点正在拉取全部群数据，请保持账号在线。同步完成后会自动显示。</p></div></div>
  <?php elseif ($rows): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:760px"><thead><tr><th class="avatar-column">头像</th><th>群 ID</th><th>群名称</th><th>成员数</th><th>操作</th></tr></thead><tbody><?php foreach ($rows as $g): ?><tr><td class="avatar-cell"><img class="list-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://p.qlogo.cn/gh/<?=urlencode($g['group_id'])?>/<?=urlencode($g['group_id'])?>/640/" onerror="avatarFallback(this)" alt=""></td><td><?=e($g['group_id'])?></td><td><?=e($g['group_name'])?></td><td><?=intval($g['member_count'])?></td><td><div class="group-actions">        <a href="?page=user_account_detail&token=<?=urlencode($token)?>&tab=members&gid=<?=urlencode($g['group_id'])?>&sync=1">查看</a><form method="post" class="group-export-form"><input type="hidden" name="action" value="export_members"><input type="hidden" name="group_id" value="<?=e($g['group_id'])?>"><label class="compact-check"><input type="checkbox" name="exclude_management" value="1">排除管理</label><button type="submit" class="btn btn-sm btn-gray">导出</button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pager($groupsPage, $pages, ['page' => 'user_account_detail', 'token' => $token, 'tab' => 'groups']); ?>
  <?php else: ?><p class="empty">暂无群数据</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'members' && !empty($_GET['gid'])): ?>
  <?php
  $gid = strval($_GET['gid']);
  $groupStmt = db()->prepare('SELECT * FROM groups_data WHERE account_uin=? AND group_id=?');
  $groupStmt->execute([$uin, $gid]);
  $group = $groupStmt->fetch();
  $membersPage = page_number('p');
  $perPage = 20;
  $countStmt = db()->prepare('SELECT COUNT(*) FROM members WHERE account_uin=? AND group_id=?');
  $countStmt->execute([$uin, $gid]);
  $total = intval($countStmt->fetchColumn());
  $pages = max(1, intval(ceil($total / $perPage)));
  if ($membersPage > $pages) $membersPage = $pages;
  $offset = ($membersPage - 1) * $perPage;
  $stmt = db()->prepare('SELECT * FROM members WHERE account_uin=? AND group_id=? ORDER BY role DESC,user_id LIMIT ? OFFSET ?');
  $stmt->bindValue(1, $uin, PDO::PARAM_STR);
  $stmt->bindValue(2, $gid, PDO::PARAM_STR);
  $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(4, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();
  ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <a href="?page=user_account_detail&token=<?=urlencode($token)?>&tab=groups" class="btn btn-sm btn-gray">返回群列表</a>
    <form method="post" style="display:inline" onsubmit="showSyncPanel('members')"><input type="hidden" name="action" value="refresh_members"><input type="hidden" name="group_id" value="<?=e($gid)?>"><button type="submit" class="btn btn-sm" <?=in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>重新同步</button></form>
    <form method="post" class="group-export-form"><input type="hidden" name="action" value="export_members"><input type="hidden" name="group_id" value="<?=e($gid)?>"><label class="compact-check"><input type="checkbox" name="exclude_management" value="1">排除群主/管理员</label><button type="submit" class="btn btn-sm btn-gray">导出</button></form>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0"><?=e($group['group_name'] ?? $gid)?>，共 <?=$total?> 人</span>
  </div>
  <div id="membersListBox">
  <?php if (in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在拉取群成员</strong><p>节点正在获取该群的全部成员，数据较多时请稍候。刷新页面也不会中断。</p></div></div>
  <?php elseif ($rows): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:620px"><thead><tr><th>QQ 号</th><th>昵称</th><th>群名片</th><th>角色</th></tr></thead><tbody><?php foreach ($rows as $m): ?><tr><td><?=e($m['user_id'])?></td><td><?=e(clean_display_name($m['nickname'] ?? '', '-'))?></td><td><?=e(clean_display_name($m['card'] ?? '', '-'))?></td><td><?=e($m['role'])?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pager($membersPage, $pages, ['page' => 'user_account_detail', 'token' => $token, 'tab' => 'members', 'gid' => $gid]); ?>
  <?php else: ?><p class="empty">暂无成员数据</p><?php endif; ?>
  </div>
<?php endif; ?>

<script>
var managedUin = <?=json_encode($uin, JSON_UNESCAPED_UNICODE)?>;
var userQrTimer = null;
var currentSyncType = <?=json_encode($tab === 'groups' ? 'groups' : ($tab === 'friends' ? 'friends' : ($tab === 'members' ? 'members' : '')))?>;
var currentSyncGroupId = <?=json_encode(strval($memberGroupId ?? ''))?>;
var currentSyncActive = <?=json_encode(($tab === 'friends' && in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)) || ($tab === 'groups' && in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)) || ($tab === 'members' && in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)))?>;

function showSyncPanel(type) {
  var box = document.getElementById(type + 'ListBox');
  if (!box) return true;
  var title = type === 'friends' ? '正在同步好友列表' : (type === 'groups' ? '正在同步群列表' : '正在拉取群成员');
  box.innerHTML = '<div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>' + title + '</strong><p>任务已提交，节点正在拉取数据。刷新页面也会继续显示同步状态。</p></div></div>';
  return true;
}

function pollAccountSync() {
  if (!currentSyncType || !currentSyncActive) return;
  var action = currentSyncType === 'friends' ? 'refresh_friends' : (currentSyncType === 'groups' ? 'refresh_groups' : 'refresh_members');
  var groupQuery = currentSyncType === 'members' ? '&group_id=' + encodeURIComponent(currentSyncGroupId) : '';
  fetch('/?page=api_user_sync_status&uin=' + encodeURIComponent(managedUin) + '&action=' + encodeURIComponent(action) + groupQuery + '&t=' + Date.now())
    .then(function(r){return r.json()}).then(function(data){
      if (!data.ok || !data.job) { setTimeout(pollAccountSync, 2500); return; }
      var status = data.job.status || '';
      if (status === 'done') { location.reload(); return; }
      if (status === 'failed') {
        currentSyncActive = false;
        var box = document.getElementById(currentSyncType + 'ListBox');
        if (box) box.innerHTML = '<div class="sync-panel sync-failed"><div class="sync-panel-inner"><strong>同步失败</strong><p>' + escapeSyncText(data.job.error || '节点同步失败，请稍后重试') + '</p></div></div>';
        return;
      }
      setTimeout(pollAccountSync, 2500);
    }).catch(function(){ setTimeout(pollAccountSync, 4000); });
}

function escapeSyncText(value) {
  var node = document.createElement('div');
  node.textContent = String(value || '');
  return node.innerHTML;
}

if (currentSyncActive) setTimeout(pollAccountSync, 1200);

function avatarFallback(image) {
  var fallback = image.getAttribute('data-fallback');
  if (fallback && image.src !== fallback) {
    image.removeAttribute('data-fallback');
    image.src = fallback;
    return;
  }
  image.onerror = null;
  image.classList.add('avatar-missing');
  image.removeAttribute('src');
}

function restartUserContainer() {
  if (!confirm('确定重启这个 NapCat 容器吗？当前容器会先停止再重新启动。')) return;
  var badge = document.getElementById('acctStatus');
  if (badge) badge.innerHTML = '<span class="spin"></span>重启中';
  fetch('/?page=api_user_restart_container', {
    method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(managedUin)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) { toast(data.error || '容器重启失败'); location.reload(); return; }
    toast('容器重启任务已提交');
    setTimeout(openUserQrModal, 2500);
  }).catch(function(){toast('请求失败'); location.reload();});
}

function recreateUserAccount() {
  if (!confirm('确定重新创建该账号的独立容器吗？创建后需要使用手机 QQ 扫码登录。')) return;
  fetch('/?page=api_user_recreate_account', {
    method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(managedUin)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) { toast(data.error || '重新创建失败'); return; }
    toast('重新创建任务已提交');
    setTimeout(function(){ location.reload(); }, 1800);
  }).catch(function(){toast('请求失败')});
}

function openUserQrModal() {
  document.getElementById('userQrModal').classList.add('show');
  document.getElementById('userQrImage').style.display = 'none';
  document.getElementById('userQrLoading').style.display = '';
  pollUserQrCode();
}

function pollUserQrCode() {
  if (userQrTimer) clearTimeout(userQrTimer);
  fetch('/?page=api_user_qrcode&uin=' + encodeURIComponent(managedUin) + '&t=' + Date.now())
    .then(function(r){return r.json()}).then(function(data){
      if (!data.ok) { document.getElementById('userQrStatus').textContent = data.error || '获取失败'; return; }
      if (data.login_status === 'online') {
        document.getElementById('userQrStatus').textContent = '账号已登录';
        document.getElementById('userQrImage').style.display = 'none';
        document.getElementById('userQrLoading').style.display = 'none';
        if (userQrTimer) clearTimeout(userQrTimer);
        userQrTimer = null;
        setTimeout(function(){ location.reload(); }, 1200);
        return;
      }
      document.getElementById('userQrStatus').textContent = data.login_error || (data.qr_image ? '请使用手机 QQ 扫码并确认登录' : '容器正在启动，等待二维码...');
      if (data.qr_image) {
        document.getElementById('userQrImage').onerror = function(){
          this.style.display = 'none';
          document.getElementById('userQrLoading').style.display = 'none';
          document.getElementById('userQrStatus').textContent = '二维码图片加载失败，请点击刷新二维码';
        };
        document.getElementById('userQrImage').src = data.qr_image;
        document.getElementById('userQrImage').style.display = 'block';
        document.getElementById('userQrLoading').style.display = 'none';
      }
      userQrTimer = setTimeout(pollUserQrCode, 5000);
    }).catch(function(){ userQrTimer = setTimeout(pollUserQrCode, 5000); });
}

function refreshUserQrCode() {
  document.getElementById('userQrStatus').textContent = '正在刷新二维码...';
  fetch('/?page=api_user_refresh_qrcode', {
    method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(managedUin)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) { toast(data.error || '刷新失败'); return; }
    document.getElementById('userQrImage').style.display = 'none';
    document.getElementById('userQrLoading').style.display = '';
    userQrTimer = setTimeout(pollUserQrCode, 2000);
  }).catch(function(){toast('请求失败')});
}

function closeUserQrModal() {
  if (userQrTimer) clearTimeout(userQrTimer);
  userQrTimer = null;
  document.getElementById('userQrModal').classList.remove('show');
}
</script>
