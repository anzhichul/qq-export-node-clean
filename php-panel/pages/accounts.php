<?php
$uin = $_GET['uin'] ?? '';
$tab = $_GET['tab'] ?? '';
$error = $error ?? '';

function account_status_label($account, $isOnline, $needsTicket, $needsDevice) {
  if ($isOnline) return '在线';
  if ($needsTicket) return '需滑块验证';
  if ($needsDevice) return '需设备验证';
  $status = strval($account['login_status'] ?? '');
  if ($status === 'restarting') return '重启中';
  if ($status === 'creating') return '创建中';
  if ($status === 'waiting_scan') return '等待手机确认';
  if ($status === 'waiting_qrcode') return '等待二维码';
  if ($status === 'logged_in_pending_adapter') return '已登录，启动采集中';
  if ($status === 'idle_offline') return '离线';
  if ($status === 'offline') return '离线';
  if ($status === 'node_offline') return '节点离线';
  if ($status === 'create_failed') return '登录失败';
  return $status ?: '离线';
}

function account_status_detail($account, $isOnline) {
  if ($isOnline) return '节点在线，账号已登录';
  $error = trim(strval($account['login_error'] ?? ''));
  $status = strval($account['login_status'] ?? '');
  if ($error !== '') return $error;
  if ($status === 'restarting') return '容器正在重启中，请稍候';
  if ($status === 'creating') return '正在拉起 QQ/NapCat 并发起登录';
  if ($status === 'waiting_scan') return '请使用手机 QQ 扫码并确认登录';
  if ($status === 'waiting_qrcode') return '正在等待生成二维码';
  if ($status === 'logged_in_pending_adapter') return 'QQ 已登录成功，但好友/群采集接口还在启动中';
  if ($status === 'idle_offline') return '创建或重启后3分钟未登录，已自动离线，重新操作会自动拉起';
  if ($status === 'node_offline') return '本机节点当前不在线';
  return '当前未登录';
}

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

if ($uin && $tab === 'members' && !empty($_GET['gid']) && ($_GET['sync'] ?? '') === '1') {
  try {
    $groupId = preg_replace('/\D/', '', strval($_GET['gid']));
    if ($groupId === '') throw new Exception('群号格式错误');
    $activeJob = latest_account_sync_job($uin, 'refresh_members', $groupId);
    if (!in_array(strval($activeJob['status'] ?? ''), ['pending', 'running'], true)) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/members/refresh', '{}');
    }
    echo '<script>location.replace(' . json_encode('?page=accounts&uin=' . urlencode($uin) . '&tab=members&gid=' . urlencode($groupId)) . ')</script>'; exit;
  } catch (Exception $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    if ($_POST['action'] === 'refresh_friends' && $uin) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/friends/refresh', '{}');
      echo '<script>location.replace(' . json_encode('?page=accounts&uin=' . urlencode($uin) . '&tab=friends') . ')</script>'; exit;
    }
    if ($_POST['action'] === 'refresh_groups' && $uin) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/refresh', '{}');
      echo '<script>location.replace(' . json_encode('?page=accounts&uin=' . urlencode($uin) . '&tab=groups') . ')</script>'; exit;
    }
    if ($_POST['action'] === 'refresh_members' && $uin && isset($_POST['group_id'])) {
      node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($_POST['group_id']) . '/members/refresh', '{}');
      echo '<script>location.replace(' . json_encode('?page=accounts&uin=' . urlencode($uin) . '&tab=members&gid=' . urlencode($_POST['group_id'])) . ')</script>'; exit;
    }
    if ($_POST['action'] === 'export_friends' && $uin) {
      $res = node_api('GET', '/api/accounts/' . urlencode($uin) . '/friends/export');
      echo '<script>alert("已导出 ' . intval($res['count'] ?? 0) . ' 个好友");location.href="?page=accounts&uin=' . urlencode($uin) . '&tab=friends";</script>'; exit;
    }
    if ($_POST['action'] === 'export_members' && $uin && isset($_POST['group_id'])) {
      $groupId = strval($_POST['group_id']);
      $excludeManagement = !empty($_POST['exclude_management']);
      $job = node_api('POST', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/members/refresh', '{}');
      $jobId = strval($job['job_id'] ?? '');
      if (!$jobId) throw new Exception('同步任务创建失败');
      $done = false;
      $failed = '';
      for ($i = 0; $i < 25; $i++) {
        usleep(1000 * 1000);
        $jobs = node_api('GET', '/api/jobs')['jobs'] ?? [];
        foreach ($jobs as $item) {
          if (($item['id'] ?? '') !== $jobId) continue;
          if (($item['status'] ?? '') === 'done') {
            $done = true;
          } elseif (($item['status'] ?? '') === 'failed') {
            $failed = strval($item['error'] ?? '同步失败');
          }
          break;
        }
        if ($done || $failed !== '') break;
      }
      if ($failed !== '') throw new Exception($failed);
      if (!$done) throw new Exception('同步群成员超时，请稍后重试');
      $res = node_api('GET', '/api/accounts/' . urlencode($uin) . '/groups/' . urlencode($groupId) . '/export/members' . ($excludeManagement ? '?exclude_management=1' : ''));
      echo '<script>alert("已导出 ' . intval($res['count'] ?? 0) . ' 个成员' . ($excludeManagement ? '（已排除群主和管理员）' : '') . '");location.href="?page=accounts&uin=' . urlencode($uin) . '&tab=' . urlencode($tab ?: 'groups') . ($tab === 'members' ? '&gid=' . urlencode($groupId) : '') . '";</script>'; exit;
    }
  } catch (Exception $e) { $error = $e->getMessage(); }
}

try {
  $accounts = db()->query('SELECT a.*,n.name node_name,ua.username owner_username FROM accounts a LEFT JOIN nodes n ON n.node_id=a.node_id LEFT JOIN user_accounts ua ON ua.account_uin=a.uin ORDER BY a.online DESC,a.uin')->fetchAll();
  $nodes = node_api('GET', '/api/nodes')['nodes'] ?? [];
} catch (Exception $e) { echo '<p style="color:#c73d3d">' . e($e->getMessage()) . '</p>'; return; }
?>
<h2 class="page-title">账号管理</h2>
<?php if (!$uin): ?>
<div style="margin-bottom:14px;display:flex;gap:8px;align-items:center">
  <button class="btn" onclick="showAddAccount()">+ 添加账户</button>
  <span style="font-size:12px;color:#6d7c8d">共 <?=count($accounts)?> 个账号</span>
</div>

<div class="card">
<div class="table-wrap" style="-webkit-overflow-scrolling:touch">
<table style="width:840px;font-size:12px;white-space:nowrap"><thead><tr><th class="avatar-column">头像</th><th>QQ 号码</th><th>名字</th><th>归属用户</th><th>是否离线</th><th>操作</th></tr></thead>
<tbody>
<?php foreach ($accounts as $a):
  $isOnline = $a['online'] && $a['last_seen'] >= (time() - 45);
  $isSelected = $uin === $a['uin'];
  $displayNickname = clean_display_name($a['nickname'] ?? '', '-');
  $loginErr = $a['login_error'] ?? '';
  $isReleased = ($a['login_status'] ?? '') === 'idle_offline';
  $needsTicket = strpos($loginErr, 'slider_url:') === 0;
  $needsDevice = strpos($loginErr, 'device_url:') === 0;
  $statusLabel = account_status_label($a, $isOnline, $needsTicket, $needsDevice);
  $statusDetail = account_status_detail($a, $isOnline);
?>
<tr style="<?=$isSelected?'background:#edf5ff':''?>">
  <td class="avatar-cell"><img class="list-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($a['uin'])?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($a['uin'])?>/<?=urlencode($a['uin'])?>/100" onerror="avatarFallback(this)" alt=""></td>
  <td><?=e($a['uin'])?></td>
  <td><?=e($displayNickname)?></td>
  <td><?=e($a['owner_username'] ?: '-')?></td>
  <td>
    <span class="badge <?=$isOnline?'on':'off'?>" id="status-<?=e($a['uin'])?>" title="<?=e($statusDetail)?>"><?=e(account_status_label($a, $isOnline, $needsTicket, $needsDevice))?></span>
  </td>
  <td><div class="account-list-actions">
    <button class="btn btn-sm btn-gray" onclick="deleteAccount('<?=e($a['uin'])?>',this)">删除</button>
    <a href="?page=accounts&uin=<?=urlencode($a['uin'])?>&tab=friends" class="btn btn-sm">进入管理</a>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>
<?php endif; ?>

<?php if ($uin): $isOnline = false; $selected = null; $selectedNeedsTicket = false; $selectedNeedsDevice = false; foreach ($accounts as $a) { if ($a['uin'] === $uin) { $isOnline = $a['online'] && $a['last_seen'] >= (time() - 45); $selected = $a; $selectedNeedsTicket = strpos($a['login_error'] ?? '', 'slider_url:') === 0; $selectedNeedsDevice = strpos($a['login_error'] ?? '', 'device_url:') === 0; break; } } $selectedStatusDetail = $selected ? account_status_detail($selected, $isOnline) : ''; $selectedNickname = clean_display_name($selected['nickname'] ?? '', $uin); $selectedCreateFailed = ($selected['login_status'] ?? '') === 'create_failed'; ?>
<?php $friendsSyncJob = latest_account_sync_job($uin, 'refresh_friends'); $groupsSyncJob = latest_account_sync_job($uin, 'refresh_groups'); ?>
<?php $memberGroupId = $tab === 'members' ? preg_replace('/\D/', '', strval($_GET['gid'] ?? '')) : ''; $membersSyncJob = $memberGroupId !== '' ? latest_account_sync_job($uin, 'refresh_members', $memberGroupId) : null; ?>
<div class="card">
<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
  <img class="account-detail-avatar" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($uin)?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($uin)?>/<?=urlencode($uin)?>/100" onerror="avatarFallback(this)" alt="">
  <h3 style="margin:0">QQ <?=e($uin)?> <?=e($selectedNickname)?></h3>
  <span class="badge <?=$isOnline?'on':'off'?>" id="selStatus"><?=e(account_status_label($selected ?: [], $isOnline, $selectedNeedsTicket, $selectedNeedsDevice))?></span>
  <div style="margin-left:auto;display:flex;gap:6px">
    <a href="?page=accounts&uin=<?=urlencode($uin)?>&tab=friends" class="btn btn-sm <?=$tab==='friends'?'':'btn-gray'?>">好友</a>
    <a href="?page=accounts&uin=<?=urlencode($uin)?>&tab=groups" class="btn btn-sm <?=$tab==='groups'?'':'btn-gray'?>">群</a>
  </div>
</div>
<?php if ($selectedStatusDetail): ?>
<div style="margin:-2px 0 12px;color:#6d7c8d;font-size:13px">当前状态：<?=e($selectedStatusDetail)?></div>
<?php endif; ?>
<div class="account-manage-actions">
  <a href="?page=accounts" class="btn btn-sm btn-gray">返回账号列表</a>
  <?php if ($selectedNeedsTicket || $selectedNeedsDevice): ?>
  <button class="btn btn-sm" onclick="showChallenge('<?=e($uin)?>')">继续验证</button>
  <?php endif; ?>
  <?php if ($selectedCreateFailed): ?><button class="btn btn-sm" onclick="recreateAccount('<?=e($uin)?>')">重新创建</button><?php else: ?><button class="btn btn-sm" onclick="restartContainer('<?=e($uin)?>')">重启容器</button><?php endif; ?>
  <?php if (!$isOnline && !$selectedCreateFailed): ?><button class="btn btn-sm btn-gray" onclick="showQrCode('<?=e($uin)?>')">查看二维码</button><?php endif; ?>
</div>

<?php if ($tab === 'friends' || !$tab): ?>
  <?php
  $friendsPage = page_number('p');
  $friendsPerPage = 20;
  $friendsCountStmt = db()->prepare('SELECT COUNT(*) FROM friends WHERE account_uin=?');
  $friendsCountStmt->execute([$uin]);
  $friendsTotal = intval($friendsCountStmt->fetchColumn());
  $friendsTotalPages = max(1, intval(ceil($friendsTotal / $friendsPerPage)));
  if ($friendsPage > $friendsTotalPages) $friendsPage = $friendsTotalPages;
  $friendsOffset = ($friendsPage - 1) * $friendsPerPage;
  $friendsStmt = db()->prepare('SELECT * FROM friends WHERE account_uin=? ORDER BY remark,nickname,user_id LIMIT ? OFFSET ?');
  $friendsStmt->bindValue(1, $uin, PDO::PARAM_STR);
  $friendsStmt->bindValue(2, $friendsPerPage, PDO::PARAM_INT);
  $friendsStmt->bindValue(3, $friendsOffset, PDO::PARAM_INT);
  $friendsStmt->execute();
  $friends = $friendsStmt->fetchAll();
  ?>
  <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
    <?php if ($isOnline): ?>
    <form method="post" style="display:inline" onsubmit="showSyncPanel('friends')"><input type="hidden" name="action" value="refresh_friends">
      <button type="submit" class="btn btn-sm" <?=in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>从节点获取</button></form>
    <?php endif; ?>
    <form method="post" style="display:inline"><input type="hidden" name="action" value="export_friends">
      <button type="submit" class="btn btn-sm btn-gray">导出好友</button></form>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0">共 <?=$friendsTotal?> 个好友</span>
  </div>
  <div id="friendsListBox">
  <?php if (in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在同步好友列表</strong><p>节点正在拉取好友数据，刷新页面也不会中断。</p></div></div>
  <?php elseif ($friends): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:720px;font-size:12px"><thead><tr><th class="avatar-column">头像</th><th>QQ 号</th><th>昵称</th><th>备注</th></tr></thead>
  <tbody><?php foreach ($friends as $f): $friendUin = strval($f['user_id']); ?><tr><td class="avatar-cell"><img class="list-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://q.qlogo.cn/g?b=qq&s=100&nk=<?=urlencode($friendUin)?>" data-fallback="https://qlogo4.store.qq.com/qzone/<?=urlencode($friendUin)?>/<?=urlencode($friendUin)?>/100" onerror="avatarFallback(this)" alt=""></td><td><?=e($friendUin)?></td><td><?=e(clean_display_name($f['nickname'] ?? '', '-'))?></td><td><?=e(clean_display_name($f['remark'] ?? '', '-'))?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pager($friendsPage, $friendsTotalPages, ['page' => 'accounts', 'uin' => $uin, 'tab' => 'friends']); ?>
  <?php else: ?><p class="empty">暂无好友数据</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'groups'): ?>
  <?php
  $groupsPage = page_number('p');
  $groupsPerPage = 20;
  $groupsCountStmt = db()->prepare('SELECT COUNT(*) FROM groups_data WHERE account_uin=?');
  $groupsCountStmt->execute([$uin]);
  $groupsTotal = intval($groupsCountStmt->fetchColumn());
  $groupsTotalPages = max(1, intval(ceil($groupsTotal / $groupsPerPage)));
  if ($groupsPage > $groupsTotalPages) $groupsPage = $groupsTotalPages;
  $groupsOffset = ($groupsPage - 1) * $groupsPerPage;
  $groupsStmt = db()->prepare('SELECT * FROM groups_data WHERE account_uin=? ORDER BY group_name,group_id LIMIT ? OFFSET ?');
  $groupsStmt->bindValue(1, $uin, PDO::PARAM_STR);
  $groupsStmt->bindValue(2, $groupsPerPage, PDO::PARAM_INT);
  $groupsStmt->bindValue(3, $groupsOffset, PDO::PARAM_INT);
  $groupsStmt->execute();
  $groups = $groupsStmt->fetchAll();
  ?>
  <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
    <?php if ($isOnline): ?>
    <form method="post" style="display:inline" onsubmit="showSyncPanel('groups')"><input type="hidden" name="action" value="refresh_groups">
      <button type="submit" class="btn btn-sm" <?=in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>从节点获取群列表</button></form>
    <?php endif; ?>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0">共 <?=$groupsTotal?> 个群</span>
  </div>
  <div id="groupsListBox">
  <?php if (in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在同步群列表</strong><p>节点正在拉取全部群数据，同步完成后会自动显示。</p></div></div>
  <?php elseif ($groups): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:760px;font-size:12px"><thead><tr><th class="avatar-column">头像</th><th>群 ID</th><th>群名称</th><th>成员数</th><th>操作</th></tr></thead>
  <tbody><?php foreach ($groups as $g): ?>
    <tr>
      <td class="avatar-cell"><img class="list-avatar" loading="lazy" referrerpolicy="no-referrer" src="https://p.qlogo.cn/gh/<?=urlencode($g['group_id'])?>/<?=urlencode($g['group_id'])?>/640/" onerror="avatarFallback(this)" alt=""></td>
      <td><?=e($g['group_id'])?></td>
      <td><?=e($g['group_name'])?></td>
      <td><?=$g['member_count']?></td>
      <td><div class="group-actions">
        <a href="?page=accounts&uin=<?=urlencode($uin)?>&tab=members&gid=<?=urlencode($g['group_id'])?>&sync=1" class="btn btn-sm btn-gray">查看成员</a>
        <form method="post" class="group-export-form"><input type="hidden" name="action" value="export_members"><input type="hidden" name="group_id" value="<?=e($g['group_id'])?>"><label class="compact-check"><input type="checkbox" name="exclude_management" value="1">排除管理</label><button type="submit" class="btn btn-sm btn-gray">导出</button></form>
      </div></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php render_pager($groupsPage, $groupsTotalPages, ['page' => 'accounts', 'uin' => $uin, 'tab' => 'groups']); ?>
  <?php else: ?><p class="empty">暂无群数据</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'members'): $gid = $_GET['gid'] ?? ''; if ($gid):
  $groupStmt = db()->prepare('SELECT * FROM groups_data WHERE account_uin=? AND group_id=?'); $groupStmt->execute([$uin, $gid]); $group = $groupStmt->fetch();
  $membersPage = page_number('p');
  $membersPerPage = 20;
  $membersCountStmt = db()->prepare('SELECT COUNT(*) FROM members WHERE account_uin=? AND group_id=?');
  $membersCountStmt->execute([$uin, $gid]);
  $membersTotal = intval($membersCountStmt->fetchColumn());
  $membersTotalPages = max(1, intval(ceil($membersTotal / $membersPerPage)));
  if ($membersPage > $membersTotalPages) $membersPage = $membersTotalPages;
  $membersOffset = ($membersPage - 1) * $membersPerPage;
  $membersStmt = db()->prepare('SELECT * FROM members WHERE account_uin=? AND group_id=? ORDER BY role DESC,user_id LIMIT ? OFFSET ?');
  $membersStmt->bindValue(1, $uin, PDO::PARAM_STR);
  $membersStmt->bindValue(2, $gid, PDO::PARAM_STR);
  $membersStmt->bindValue(3, $membersPerPage, PDO::PARAM_INT);
  $membersStmt->bindValue(4, $membersOffset, PDO::PARAM_INT);
  $membersStmt->execute();
  $members = $membersStmt->fetchAll(); ?>
  <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
    <h4 style="margin:0;font-size:14px"><?=e($group['group_name']?:$gid)?></h4>
    <?php if ($isOnline): ?>
    <form method="post" style="display:inline" onsubmit="showSyncPanel('members')"><input type="hidden" name="action" value="refresh_members"><input type="hidden" name="group_id" value="<?=e($gid)?>"><button type="submit" class="btn btn-sm" <?=in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)?'disabled':''?>>重新同步</button></form>
    <?php endif; ?>
    <form method="post" class="group-export-form"><input type="hidden" name="action" value="export_members"><input type="hidden" name="group_id" value="<?=e($gid)?>"><label class="compact-check"><input type="checkbox" name="exclude_management" value="1">排除群主/管理员</label><button type="submit" class="btn btn-sm btn-gray">导出</button></form>
    <span style="font-size:12px;color:#6d7c8d;margin:auto 0">共 <?=$membersTotal?> 人</span>
  </div>
  <div id="membersListBox">
  <?php if (in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)): ?>
  <div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>正在拉取群成员</strong><p>节点正在获取该群的全部成员，数据较多时请稍候。刷新页面也不会中断。</p></div></div>
  <?php elseif ($members): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch"><table style="width:620px;font-size:12px"><thead><tr><th>QQ 号</th><th>昵称</th><th>群名片</th><th>角色</th></tr></thead>
  <tbody><?php foreach ($members as $m): ?><tr><td><?=e($m['user_id'])?></td><td><?=e(clean_display_name($m['nickname'] ?? '', '-'))?></td><td><?=e(clean_display_name($m['card'] ?? '', '-'))?></td><td><?=e($m['role'])?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pager($membersPage, $membersTotalPages, ['page' => 'accounts', 'uin' => $uin, 'tab' => 'members', 'gid' => $gid]); ?>
  <?php else: ?><p class="empty">暂无成员数据</p><?php endif; ?>
  </div>
<?php endif; endif; ?>
</div>
<?php endif; ?>

<div class="modal" id="addAccountModal">
  <div class="modal-box">
    <h2>添加 QQ 账户</h2>
    <div class="form-group"><label>QQ 号</label><input id="addUin" placeholder="请输入 QQ 号" oninput="this.value=this.value.replace(/\D/g,'')"></div>
    <div class="form-group"><label>选择节点</label>
      <select id="addNode">
        <option value="">-- 请选择在线节点 --</option>
        <?php foreach ($nodes as $n): if ($n['online']): ?>
        <option value="<?=e($n['node_id'])?>"><?=e($n['name'] ?: $n['node_id'])?></option>
        <?php endif; endforeach; ?>
      </select></div>
    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" onclick="submitAddAccount()" id="addBtn">提交</button>
      <button class="btn btn-gray" onclick="closeModal('addAccountModal')">取消</button>
    </div>
  </div>
</div>

<div class="modal" id="qrModal">
  <div class="modal-box" style="text-align:center">
    <h2>扫码登录</h2>
    <p style="color:#6d7c8d;font-size:13px;margin-bottom:14px" id="qrStatus">等待二维码...</p>
    <p style="color:#8a97a8;font-size:12px;margin:-6px 0 14px">系统不会自动登录或复用其他账号登录态，请使用手机 QQ 扫码确认。</p>
    <div id="qrImageWrap" style="min-height:200px;display:grid;place-items:center">
      <img id="qrImage" src="" style="max-width:280px;border:1px solid #dce3ed;border-radius:8px;display:none">
      <span id="qrLoading" style="color:#999">加载中...</span>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:16px">
      <button class="btn" onclick="checkLoggedIn()">我已登录</button>
      <button class="btn" onclick="refreshQrCode()">刷新二维码</button>
      <button class="btn btn-gray" onclick="closeQrModal()">关闭</button>
    </div>
  </div>
</div>

<div class="modal" id="challengeModal">
  <div class="modal-box" style="text-align:center;width:min(520px,calc(100% - 30px))">
    <h2>登录验证</h2>
    <p style="color:#6d7c8d;font-size:13px;margin-bottom:14px" id="challengeStatus">等待验证链接...</p>
    <div style="margin-bottom:14px">
      <button class="btn" onclick="openChallengeWindow()" id="challengeOpenBtn">打开验证页面</button>
      <button class="btn btn-gray" onclick="submitNewDevice()" id="newDeviceBtn" style="display:none">我已完成设备验证</button>
    </div>
    <p style="font-size:12px;color:#999;margin-bottom:10px">系统会尝试自动抓取验证码回跳 URL；如果浏览器不允许，将保留手动提交兜底。</p>
    <div class="form-group">
      <textarea id="challengeResultUrl" rows="2" placeholder="自动抓取失败时，把验证完成后的完整 URL 粘贴到这里"></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:center">
      <button class="btn" onclick="submitChallenge()" id="challengeSubmitBtn">提交验证</button>
      <button class="btn btn-gray" onclick="closeChallengeModal()">关闭</button>
    </div>
  </div>
</div>

<script>
var qrPollTimer = null;
var challengePollTimer = null;
var challengeWindow = null;
var currentQrUin = '';
var currentChallengeUin = '';
var currentChallengeType = '';
var currentChallengeUrl = '';
var managedSyncUin = <?=json_encode(strval($uin), JSON_UNESCAPED_UNICODE)?>;
var currentSyncType = <?=json_encode($uin && $tab === 'groups' ? 'groups' : ($uin && ($tab === 'friends' || !$tab) ? 'friends' : ($uin && $tab === 'members' ? 'members' : '')))?>;
var currentSyncGroupId = <?=json_encode(strval($memberGroupId ?? ''))?>;
var currentSyncActive = <?=json_encode(($uin && ($tab === 'friends' || !$tab) && in_array(strval($friendsSyncJob['status'] ?? ''), ['pending','running'], true)) || ($uin && $tab === 'groups' && in_array(strval($groupsSyncJob['status'] ?? ''), ['pending','running'], true)) || ($uin && $tab === 'members' && in_array(strval($membersSyncJob['status'] ?? ''), ['pending','running'], true)))?>;

function showSyncPanel(type) {
  var box = document.getElementById(type + 'ListBox');
  if (!box) return true;
  var title = type === 'friends' ? '正在同步好友列表' : (type === 'groups' ? '正在同步群列表' : '正在拉取群成员');
  box.innerHTML = '<div class="sync-panel"><div class="sync-panel-inner"><div class="sync-spinner"></div><strong>' + title + '</strong><p>任务已提交，节点正在拉取数据。刷新页面也会继续显示同步状态。</p></div></div>';
  return true;
}

function escapeSyncText(value) {
  var node = document.createElement('div');
  node.textContent = String(value || '');
  return node.innerHTML;
}

function pollAccountSync() {
  if (!managedSyncUin || !currentSyncType || !currentSyncActive) return;
  var action = currentSyncType === 'friends' ? 'refresh_friends' : (currentSyncType === 'groups' ? 'refresh_groups' : 'refresh_members');
  var groupQuery = currentSyncType === 'members' ? '&group_id=' + encodeURIComponent(currentSyncGroupId) : '';
  fetch('?page=api_sync_status&uin=' + encodeURIComponent(managedSyncUin) + '&action=' + encodeURIComponent(action) + groupQuery + '&t=' + Date.now())
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

if (currentSyncActive) setTimeout(pollAccountSync, 1200);

function normalizeLoginMessage(status, err) {
  err = err || '';
  if (err.indexOf('slider_url:') === 0) return '需要完成滑块验证';
  if (err.indexOf('device_url:') === 0) return '需要完成设备验证';
  if (status === 'waiting_scan') return '请使用当前二维码，并在手机 QQ 上完成最终确认';
  if (status === 'waiting_qrcode') return '正在等待生成二维码';
  if (status === 'creating') return '正在启动独立账号容器，请稍候';
  if (status === 'logged_in_pending_adapter') return 'QQ 已登录成功，正在启动好友/群采集接口';
  if (status === 'idle_offline') return '3分钟未登录，已自动离线，重新操作时会自动拉起';
  if (status === 'node_offline') return '本机节点当前离线';
  return err || '等待登录...';
}

function showAddAccount() { document.getElementById('addAccountModal').classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

function submitAddAccount() {
  var uin = document.getElementById('addUin').value.trim();
  var node = document.getElementById('addNode').value;
  if (!uin) { toast('请输入 QQ 号'); return; }
  if (!node) { toast('请选择节点'); return; }
  var btn = document.getElementById('addBtn');
  btn.disabled = true;
  btn.textContent = '提交中...';
  var body = 'action=create&uin=' + encodeURIComponent(uin) + '&node_id=' + encodeURIComponent(node);
  fetch('?page=api_accounts', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: body
  }).then(function(r){return r.json()}).then(function(data){
    btn.disabled = false;
    btn.textContent = '提交';
    if (!data.ok) { toast(data.error || '创建失败'); return; }
    closeModal('addAccountModal');
    showQrCode(uin);
  }).catch(function(){
    btn.disabled = false;
    btn.textContent = '提交';
    toast('请求失败');
  });
}

function startLoginPoll(uin) {
  var startedAt = Date.now();
  function poll() {
    fetch('?page=api_qrcode&uin=' + encodeURIComponent(uin) + '&t=' + Date.now())
      .then(function(r){return r.json()})
      .then(function(data){
        if (!data.ok) { toast(data.error || '获取状态失败'); return; }
        var err = data.login_error || '';
        if (err.indexOf('slider_url:') === 0 || err.indexOf('device_url:') === 0) {
          showChallengeWithState(uin, data);
          return;
        }
        if (data.login_status === 'online') { location.reload(); return; }
        if (data.login_status === 'create_failed') { toast(data.login_error || '账号未创建成功'); setTimeout(function(){ location.href='?page=accounts&uin='+encodeURIComponent(uin); }, 1200); return; }
        if (data.qr_image) { showQrCode(uin); return; }
        if (Date.now() - startedAt < 90000) {
          setTimeout(poll, 3000);
        } else {
          toast('等待登录超时，请稍后手动打开二维码或验证窗口');
        }
      }).catch(function(){ setTimeout(poll, 5000); });
  }
  poll();
}

function showChallenge(uin) {
  fetch('?page=api_qrcode&uin=' + encodeURIComponent(uin) + '&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (!data.ok) { toast(data.error || '获取状态失败'); return; }
      showChallengeWithState(uin, data);
    });
}

function showChallengeWithState(uin, data) {
  currentChallengeUin = uin;
  currentChallengeUrl = '';
  currentChallengeType = '';
  var err = data.login_error || '';
  if (err.indexOf('slider_url:') === 0) {
    currentChallengeType = 'captcha';
    currentChallengeUrl = err.substring(11);
  } else if (err.indexOf('device_url:') === 0) {
    currentChallengeType = 'new_device';
    currentChallengeUrl = err.substring(11);
  }
  document.getElementById('challengeModal').classList.add('show');
  document.getElementById('challengeResultUrl').value = '';
  document.getElementById('challengeOpenBtn').style.display = currentChallengeUrl ? '' : 'none';
  document.getElementById('newDeviceBtn').style.display = currentChallengeType === 'new_device' ? '' : 'none';
  document.getElementById('challengeStatus').textContent = currentChallengeType === 'new_device' ? '请完成设备验证，完成后点击“我已完成设备验证”' : '请完成滑块验证，系统会尝试自动读取回跳 URL';
}

function openChallengeWindow() {
  if (!currentChallengeUrl) return;
  if (challengeWindow && !challengeWindow.closed) challengeWindow.close();
  challengeWindow = window.open(currentChallengeUrl, '_blank', 'width=520,height=760');
  if (currentChallengeType === 'captcha') startChallengeWatcher();
}

function startChallengeWatcher() {
  if (challengePollTimer) clearInterval(challengePollTimer);
  challengePollTimer = setInterval(function(){
    if (!challengeWindow || challengeWindow.closed) {
      clearInterval(challengePollTimer);
      challengePollTimer = null;
      return;
    }
    try {
      var href = challengeWindow.location.href || '';
      if (href && /[?&](ticket|key)=/.test(href) && /[?&](randstr|rand)=/.test(href)) {
        document.getElementById('challengeResultUrl').value = href;
        clearInterval(challengePollTimer);
        challengePollTimer = null;
        submitChallenge();
      }
    } catch (error) {
    }
  }, 1000);
}

function parseChallengeUrl(urlText) {
  try {
    var url = new URL(urlText);
    return {
      ticket: url.searchParams.get('ticket') || url.searchParams.get('key') || '',
      randstr: url.searchParams.get('randstr') || url.searchParams.get('rand') || '',
      sid: url.searchParams.get('sid') || ''
    };
  } catch (error) {
    return {ticket: '', randstr: '', sid: ''};
  }
}

function submitChallenge() {
  var urlText = document.getElementById('challengeResultUrl').value.trim();
  var parsed = parseChallengeUrl(urlText);
  if (!parsed.ticket || !parsed.randstr) {
    toast('未能自动抓取时，请粘贴验证完成后的完整 URL');
    return;
  }
  var btn = document.getElementById('challengeSubmitBtn');
  btn.disabled = true;
  btn.textContent = '提交中...';
  fetch('?page=api_ticket', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(currentChallengeUin) + '&ticket=' + encodeURIComponent(parsed.ticket) + '&randstr=' + encodeURIComponent(parsed.randstr) + '&sid=' + encodeURIComponent(parsed.sid)
  }).then(function(r){return r.json()}).then(function(data){
    btn.disabled = false;
    btn.textContent = '提交验证';
    if (!data.ok) { toast(data.error || '提交失败'); return; }
    closeChallengeModal();
    showQrCode(currentChallengeUin);
  }).catch(function(){
    btn.disabled = false;
    btn.textContent = '提交验证';
    toast('请求失败');
  });
}

function submitNewDevice() {
  var btn = document.getElementById('newDeviceBtn');
  btn.disabled = true;
  btn.textContent = '提交中...';
  fetch('?page=api_new_device', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(currentChallengeUin)
  }).then(function(r){return r.json()}).then(function(data){
    btn.disabled = false;
    btn.textContent = '我已完成设备验证';
    if (!data.ok) { toast(data.error || '提交失败'); return; }
    closeChallengeModal();
    showQrCode(currentChallengeUin);
  }).catch(function(){
    btn.disabled = false;
    btn.textContent = '我已完成设备验证';
    toast('请求失败');
  });
}

function closeChallengeModal() {
  if (challengePollTimer) clearInterval(challengePollTimer);
  challengePollTimer = null;
  document.getElementById('challengeModal').classList.remove('show');
}

function showQrCode(uin) {
  currentQrUin = uin;
  document.getElementById('qrModal').classList.add('show');
  document.getElementById('qrImage').style.display = 'none';
  document.getElementById('qrLoading').style.display = '';
  document.getElementById('qrStatus').textContent = '获取二维码...';
  document.getElementById('qrImage').src = '';
  pollQrCode(uin);
}

function restartContainer(uin) {
  if (!uin) return;
  if (!confirm('确定重启这个 NapCat 容器吗？当前 QQ/NapCat 进程会先停止再重新启动。')) return;
  var badge = document.getElementById('status-' + uin);
  var sel = document.getElementById('selStatus');
  var html = '<span class="spin"></span>重启中';
  if (badge) badge.innerHTML = html;
  if (sel) sel.innerHTML = html;
  toast('正在重启容器...');
  fetch('?page=api_restart_container', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(uin)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) {
      toast(data.error || '容器重启失败');
      location.reload();
      return;
    }
    toast('容器重启任务已提交');
    setTimeout(function(){ showQrCode(uin); }, 2500);
  }).catch(function(){toast('请求失败'); location.reload();});
}

function recreateAccount(uin) {
  if (!confirm('确定重新创建该账号的独立容器吗？创建后需要使用手机 QQ 扫码登录。')) return;
  fetch('?page=api_recreate_account', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'uin='+encodeURIComponent(uin)})
    .then(function(r){return r.json()}).then(function(data){if(!data.ok){toast(data.error||'重新创建失败');return;}toast('重新创建任务已提交');setTimeout(function(){location.reload()},1800)}).catch(function(){toast('请求失败')});
}

function deleteAccount(uin, button) {
  if (!uin) return;
  if (!confirm('删除后会停止该账号的 QQ/NapCat、删除该账号本机运行目录，并清理服务器中的账号数据。不会删除共享 NapCat Shell。确定继续吗？')) return;
  button.disabled = true;
  button.textContent = '正在彻底删除...';
  fetch('?page=api_delete_account', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(uin) + '&force=1'
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) {
      button.disabled = false;
      button.textContent = '删除';
      toast(data.error || '删除失败');
      return;
    }
    if (data.deleted || !data.job_id) {
      toast('账号和相关数据已彻底删除');
      setTimeout(function(){ location.href='?page=accounts'; }, 700);
      return;
    }
    toast('删除任务正在处理，页面可以正常刷新');
    pollDeleteJob(data.job_id, button);
  }).catch(function(){button.disabled=false;button.textContent='删除';toast('删除请求失败')});
}

function pollDeleteJob(jobId, button) {
  fetch('?page=api_job_status&job_id=' + encodeURIComponent(jobId) + '&t=' + Date.now())
    .then(function(r){return r.json()}).then(function(data){
      if (!data.ok) throw new Error(data.error || '查询任务失败');
      if (data.status === 'done') {
        button.textContent = '删除完成';
        toast('账号和相关数据已彻底删除');
        setTimeout(function(){ location.href='?page=accounts'; }, 700);
        return;
      }
      if (data.status === 'failed') {
        button.disabled = false;
        button.textContent = '删除';
        toast(data.error || '删除失败');
        return;
      }
      button.textContent = '正在处理...';
      setTimeout(function(){pollDeleteJob(jobId, button)}, 1500);
    }).catch(function(error){
      button.disabled = false;
      button.textContent = '删除';
      toast(error.message || '查询删除状态失败');
    });
}

function pollQrCode(uin) {
  if (qrPollTimer) clearTimeout(qrPollTimer);
  fetch('?page=api_qrcode&uin=' + encodeURIComponent(uin) + '&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (!data.ok) {
        document.getElementById('qrStatus').textContent = data.error || '获取失败';
        document.getElementById('qrLoading').style.display = 'none';
        return;
      }
      var err = data.login_error || '';
      if (err.indexOf('slider_url:') === 0 || err.indexOf('device_url:') === 0) {
        closeQrModal();
        showChallengeWithState(uin, data);
        return;
      }
      if (data.login_status === 'online') {
        document.getElementById('qrStatus').textContent = '已登录!';
        document.getElementById('qrImage').style.display = 'none';
        document.getElementById('qrLoading').style.display = 'none';
        if (qrPollTimer) clearTimeout(qrPollTimer);
        qrPollTimer = null;
        setTimeout(function(){location.reload()}, 1500);
        return;
      }
      if (data.qr_image) {
        document.getElementById('qrImage').onerror = function(){
          this.style.display = 'none';
          document.getElementById('qrLoading').style.display = 'none';
          document.getElementById('qrStatus').textContent = '二维码图片加载失败，请点击刷新二维码';
        };
        document.getElementById('qrImage').src = data.qr_image;
        document.getElementById('qrImage').style.display = 'block';
        document.getElementById('qrLoading').style.display = 'none';
        document.getElementById('qrStatus').textContent = normalizeLoginMessage(data.login_status, err);
      } else {
        document.getElementById('qrStatus').textContent = normalizeLoginMessage(data.login_status, err);
      }
      qrPollTimer = setTimeout(function(){pollQrCode(uin)}, 5000);
    }).catch(function(){
      document.getElementById('qrStatus').textContent = '请求失败，稍后重试...';
      qrPollTimer = setTimeout(function(){pollQrCode(uin)}, 5000);
    });
}

function refreshQrCode() {
  if (!currentQrUin) return;
  document.getElementById('qrStatus').textContent = '请求刷新二维码...';
  fetch('?page=api_refresh_qrcode', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'uin=' + encodeURIComponent(currentQrUin)
  }).then(function(r){return r.json()}).then(function(data){
    if (data.ok) {
      document.getElementById('qrStatus').textContent = '已提交刷新任务';
      document.getElementById('qrImage').style.display = 'none';
      document.getElementById('qrLoading').style.display = '';
      qrPollTimer = setTimeout(function(){pollQrCode(currentQrUin)}, 2000);
    } else {
      toast(data.error || '刷新失败');
    }
  }).catch(function(){toast('请求失败')});
}

function checkLoggedIn() {
  if (!currentQrUin) return;
  document.getElementById('qrStatus').textContent = '正在检查登录状态...';
  fetch('?page=api_qrcode&uin=' + encodeURIComponent(currentQrUin) + '&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (!data.ok) {
        document.getElementById('qrStatus').textContent = data.error || '检查失败';
        return;
      }
      if (data.login_status === 'online') {
        document.getElementById('qrStatus').textContent = '已确认登录，正在刷新页面...';
        setTimeout(function(){ location.reload(); }, 800);
        return;
      }
      if (data.login_error) {
        var message = normalizeLoginMessage(data.login_status, data.login_error);
        document.getElementById('qrStatus').textContent = '未完成登录：' + message;
        toast(message);
        return;
      }
      document.getElementById('qrStatus').textContent = '尚未检测到登录成功，请在手机 QQ 上完成最终确认';
      toast('尚未检测到登录成功，请确认手机上的授权已完成');
    })
    .catch(function(){
      document.getElementById('qrStatus').textContent = '检查失败，请稍后重试';
      toast('检查失败');
    });
}

function closeQrModal() {
  if (qrPollTimer) clearTimeout(qrPollTimer);
  document.getElementById('qrModal').classList.remove('show');
  currentQrUin = '';
}
</script>
<script>
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
</script>
