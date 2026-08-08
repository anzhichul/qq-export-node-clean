<?php
$message = flash_get('message');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_node') {
  $nodeId = trim($_POST['node_id'] ?? '');
  try {
    if ($nodeId === '') throw new Exception('缺少节点 ID');
    $pdo = db();
    $nodeStmt = $pdo->prepare('SELECT * FROM nodes WHERE node_id=?');
    $nodeStmt->execute([$nodeId]);
    $node = $nodeStmt->fetch();
    if (!$node) throw new Exception('节点不存在，可能已被删除');
    if (intval($node['last_seen']) >= time() - 45) throw new Exception('节点在线，禁止删除');
    $uinStmt = $pdo->prepare('SELECT uin FROM accounts WHERE node_id=?');
    $uinStmt->execute([$nodeId]);
    $uins = $uinStmt->fetchAll(PDO::FETCH_COLUMN);
    $count = count($uins);
    if ($uins) {
      $q = implode(',', array_fill(0, count($uins), '?'));
      $pdo->prepare("DELETE FROM user_accounts WHERE account_uin IN ($q)")->execute($uins);
      $pdo->prepare("DELETE FROM friends WHERE account_uin IN ($q)")->execute($uins);
      $pdo->prepare("DELETE FROM groups_data WHERE account_uin IN ($q)")->execute($uins);
      $pdo->prepare("DELETE FROM members WHERE account_uin IN ($q)")->execute($uins);
      $pdo->prepare("DELETE FROM export_records WHERE account_uin IN ($q)")->execute($uins);
      $pdo->prepare("DELETE FROM accounts WHERE node_id=?")->execute([$nodeId]);
    }
    $pdo->prepare('DELETE FROM nodes WHERE node_id=?')->execute([$nodeId]);
    $message = '节点 ' . $nodeId . ' 已删除，清理账号 ' . $count . ' 个';
    log_operation('delete_node', 'node', $nodeId, '删除离线节点，清理账号 ' . $count . ' 个');
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=qq_nodes'));
  exit;
}

try {
  $qqNodes = node_api('GET', '/api/nodes')['nodes'] ?? [];
} catch (Exception $e) {
  echo '<div class="card"><p style="color:#c73d3d">QQ 节点数据读取失败: ' . e($e->getMessage()) . '</p></div>';
  return;
}
?>
<h2 class="page-title">QQ 节点</h2>

<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<div class="card">
  <h3>节点列表</h3>
  <p style="color:#6d7c8d;font-size:13px">仅离线节点可删除；删除会一并清理该节点下所有 QQ 账号及其关联数据（只清数据库，不影响节点端）。</p>
  <?php if ($qqNodes): ?>
  <div class="table-wrap">
  <table><thead><tr><th>节点 ID</th><th>名称</th><th>状态</th><th>最后心跳</th><th>操作</th></tr></thead>
  <tbody><?php foreach ($qqNodes as $n): $online = !empty($n['online']); ?><tr><td><?=e($n['node_id'])?></td><td><?=e($n['name'])?></td><td><?=$online?'在线':'离线'?></td><td><?=date('Y-m-d H:i:s', intval($n['last_seen']))?></td><td><?php if (!$online): ?><form method="post" onsubmit="return confirm('确定删除离线节点 <?=e($n['node_id'])?> 及其下所有 QQ 账号数据吗？此操作只清数据库，不可恢复。');"><input type="hidden" name="action" value="delete_node"><input type="hidden" name="node_id" value="<?=e($n['node_id'])?>"><button type="submit" class="btn btn-sm btn-red">删除</button></form><?php else: ?><span style="color:#6d7c8d;font-size:12px">-</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <?php else: ?><p class="empty">暂无 QQ 节点</p><?php endif; ?>
</div>
