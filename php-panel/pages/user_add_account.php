<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }
try {
  $nodes = node_api('GET', '/api/nodes')['nodes'] ?? [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uin = trim($_POST['uin'] ?? '');
    $nodeId = trim($_POST['node_id'] ?? '');
    if (!$uin || !$nodeId) throw new Exception('请填写 QQ 号并选择节点');
    $existing = db()->prepare('SELECT id FROM user_accounts WHERE account_uin=?');
    $existing->execute([$uin]);
    if ($existing->fetch()) throw new Exception('该账号已被其他用户绑定');
    $uid = intval($_SESSION['user_id']);
    $lim = db()->prepare('SELECT max_accounts,max_online_accounts FROM users WHERE id=?');
    $lim->execute([$uid]);
    $limits = $lim->fetch();
    $maxAcct = max(1, intval($limits['max_accounts'] ?? 10));
    $maxOnline = max(1, intval($limits['max_online_accounts'] ?? 2));
    $cntStmt = db()->prepare('SELECT COUNT(*) FROM user_accounts WHERE user_id=?');
    $cntStmt->execute([$uid]);
    $totalAcct = intval($cntStmt->fetchColumn());
    if ($totalAcct >= $maxAcct) throw new Exception('账户数量已达上限（' . $maxAcct . ' 个），无法继续添加');
    $ocStmt = db()->prepare('SELECT COUNT(*) FROM user_accounts ua JOIN accounts a ON a.uin=ua.account_uin WHERE ua.user_id=? AND a.online=1 AND a.last_seen>=?');
    $ocStmt->execute([$uid, time() - 45]);
    $onlineAcct = intval($ocStmt->fetchColumn());
    if ($onlineAcct >= $maxOnline) throw new Exception('同时在线账户已达上限（' . $maxOnline . ' 个），请先下线后再添加');
    node_api('POST', '/api/accounts', ['uin' => $uin, 'node_id' => $nodeId]);
    db()->prepare('INSERT INTO user_accounts(user_id,username,account_uin,token,display_name,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$_SESSION['user_id'], $_SESSION['username'], $uin, account_token(), '', 'active', now(), now()]);
    log_operation('user_bind_account', 'account', $uin, '普通用户提交账号创建');
    echo '<script>alert("账号创建任务已提交，请稍后查看状态");location.href="?page=user_home";</script>'; exit;
  }
} catch (Exception $e) { $error = $e->getMessage(); }
?>
<h2 class="page-title">添加账号</h2>
<div class="card" style="max-width:640px">
  <?php if (isset($error)): ?><p style="color:#c73d3d;font-size:13px;margin-bottom:14px"><?=e($error)?></p><?php endif; ?>
  <form method="post">
    <div class="form-group"><label>QQ 号</label><input name="uin" value="<?=e($_POST['uin']??'')?>" required></div>
    <div class="form-group"><label>选择节点</label>
      <select name="node_id" required>
        <option value="">-- 请选择在线节点 --</option>
        <?php foreach ($nodes as $node): if (!empty($node['online'])): ?>
        <option value="<?=e($node['node_id'])?>"><?=e($node['name'] ?: $node['node_id'])?></option>
        <?php endif; endforeach; ?>
      </select>
    </div>
    <div style="color:#6d7c8d;font-size:12px;margin-bottom:12px">账号创建后使用手机 QQ 扫码登录；系统不会保存 QQ 密码或自动登录。</div>
    <button type="submit" class="btn">提交创建</button>
  </form>
</div>
