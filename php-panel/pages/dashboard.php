<?php
try {
  $stmt = db()->query('SELECT COUNT(*) c FROM accounts'); $totalAccounts = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM accounts WHERE online=1 AND last_seen>=' . (time() - 45)); $onlineAccounts = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM nodes'); $totalNodes = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM nodes WHERE last_seen>=' . (time() - 45)); $onlineNodes = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM friends'); $totalFriends = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM groups_data'); $totalGroups = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM members'); $totalMembers = $stmt->fetch()['c'];
  $stmt = db()->query('SELECT COUNT(*) c FROM smtp_configs'); $smtpCount = $stmt->fetch()['c'];
  $recent = db()->query('SELECT * FROM export_records ORDER BY created_at DESC LIMIT 5')->fetchAll();
} catch (Exception $e) {
  echo '<div class="card"><p style="color:#c73d3d">数据库连接失败: ' . e($e->getMessage()) . '</p></div>';
  return;
}
?>
<h2 class="page-title">系统概览</h2>
<div class="stats">
  <div class="stat"><b><?=$totalAccounts?></b><small>账号总数</small></div>
  <div class="stat"><b><?=$onlineAccounts?></b><small>当前在线</small></div>
  <div class="stat"><b><?=$onlineNodes?>/<?=$totalNodes?></b><small>QQ 节点在线</small></div>
  <div class="stat"><b><?=$totalFriends?></b><small>好友总数</small></div>
  <div class="stat"><b><?=$totalGroups?></b><small>群总数</small></div>
  <div class="stat"><b><?=$totalMembers?></b><small>成员总数</small></div>
  <div class="stat"><b><?=$smtpCount?></b><small>SMTP 配置</small></div>
</div>

<div class="card"><h3>在线账号</h3>
<?php
$onlineList = db()->query('SELECT uin,nickname,last_seen FROM accounts WHERE online=1 AND last_seen>=' . (time() - 45) . ' ORDER BY last_seen DESC LIMIT 10')->fetchAll();
if ($onlineList): ?>
<div class="table-wrap">
<table><thead><tr><th>QQ</th><th>昵称</th><th>最后活跃</th></tr></thead>
<tbody><?php foreach ($onlineList as $a): ?><tr><td><?=e($a['uin'])?></td><td><?=e($a['nickname'])?></td><td><?=date('Y-m-d H:i:s', $a['last_seen'])?></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php else: ?><p class="empty">暂无在线账号</p><?php endif; ?>
</div>

<div class="card"><h3>最近导出</h3>
<?php if ($recent): ?>
<div class="table-wrap">
<table><thead><tr><th>账号</th><th>类型</th><th>数量</th><th>时间</th></tr></thead>
<tbody><?php foreach ($recent as $r): ?><tr><td><?=e($r['account_uin'])?></td><td><?=$r['export_type'] === 'friends' ? '好友' : ($r['export_type'] === 'members' ? '群成员' : $r['export_type'])?></td><td><?=$r['line_count']?> 人</td><td><?=date('Y-m-d H:i', $r['created_at'])?></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php else: ?><p class="empty">暂无导出记录</p><?php endif; ?>
</div>
