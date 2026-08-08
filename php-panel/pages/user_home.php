<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }
try {
  $stmt = db()->prepare('SELECT username,display_name,balance_points,membership_expires_at,last_login_at,created_at FROM users WHERE id=?');
  $stmt->execute([$_SESSION['user_id']]);
  $user = $stmt->fetch();
  $statsStmt = db()->prepare('SELECT
    (SELECT COUNT(*) FROM user_accounts ua WHERE ua.user_id=? AND ua.status="active") account_count,
    (SELECT COUNT(*) FROM friends f INNER JOIN user_accounts ua ON ua.account_uin=f.account_uin WHERE ua.user_id=? AND ua.status="active") friend_count,
    (SELECT COUNT(*) FROM groups_data g INNER JOIN user_accounts ua ON ua.account_uin=g.account_uin WHERE ua.user_id=? AND ua.status="active") group_count,
    (SELECT COUNT(*) FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE ua.user_id=?) export_count,
    (SELECT COUNT(*) FROM mail_jobs WHERE created_by=?) mail_job_count');
  $statsStmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['username']]);
  $dashboard = $statsStmt->fetch();
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">用户数据读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }
$memberExp = intval($user['membership_expires_at'] ?? 0);
$memberActive = $memberExp > now();
?>
<h2 class="page-title">个人中心</h2>
<div class="stats">
  <div class="stat"><b style="color:<?=$memberActive?'#1d7a42':'#c73d3d'?>"><?=$memberExp>0?($memberActive?'会员中':'已过期'):'未激活'?></b><small>会员状态</small></div>
  <div class="stat"><b><?=$memberExp>0?date('Y-m-d H:i', $memberExp):'-'?></b><small>会员到期</small></div>
  <div class="stat"><b><?=intval($user['balance_points'] ?? 0)?></b><small>当前点数</small></div>
  <div class="stat"><b><?=intval($dashboard['account_count'] ?? 0)?></b><small>QQ 账号</small></div>
  <div class="stat"><b><?=intval($dashboard['friend_count'] ?? 0)?></b><small>好友总数</small></div>
  <div class="stat"><b><?=intval($dashboard['group_count'] ?? 0)?></b><small>群总数</small></div>
  <div class="stat"><b><?=intval($dashboard['export_count'] ?? 0)?></b><small>导出记录</small></div>
  <div class="stat"><b><?=intval($dashboard['mail_job_count'] ?? 0)?></b><small>发信任务</small></div>
</div>
<div class="card">
  <h3>用户信息</h3>
  <p>用户名：<?=e($user['username'] ?? '')?></p>
  <p>显示名称：<?=e($user['display_name'] ?? '')?></p>
  <p>注册时间：<?=!empty($user['created_at']) ? date('Y-m-d H:i:s', intval($user['created_at'])) : '-'?></p>
  <p>上次登录：<?=!empty($user['last_login_at']) ? date('Y-m-d H:i:s', intval($user['last_login_at'])) : '-'?></p>
</div>
