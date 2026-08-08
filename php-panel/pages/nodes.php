<?php
try {
  $qqNodes = node_api('GET', '/api/nodes')['nodes'] ?? [];
  $mailNodes = node_api('GET', '/api/mail/nodes')['nodes'] ?? [];
} catch (Exception $e) {
  echo '<div class="card"><p style="color:#c73d3d">节点数据读取失败: ' . e($e->getMessage()) . '</p></div>';
  return;
}
?>
<h2 class="page-title">节点管理</h2>

<div class="card">
  <h3>QQ 节点</h3>
  <?php if ($qqNodes): ?>
  <div class="table-wrap">
  <table><thead><tr><th>节点 ID</th><th>名称</th><th>状态</th><th>最后心跳</th></tr></thead>
  <tbody><?php foreach ($qqNodes as $n): ?><tr><td><?=e($n['node_id'])?></td><td><?=e($n['name'])?></td><td><?=$n['online']?'在线':'离线'?></td><td><?=date('Y-m-d H:i:s', intval($n['last_seen']))?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <?php else: ?><p class="empty">暂无 QQ 节点</p><?php endif; ?>
</div>

<div class="card">
  <h3>邮件节点</h3>
  <?php if ($mailNodes): ?>
  <div class="table-wrap">
  <table><thead><tr><th>节点 ID</th><th>名称</th><th>并发</th><th>状态</th><th>最后心跳</th></tr></thead>
  <tbody><?php foreach ($mailNodes as $n): ?><tr><td><?=e($n['node_id'])?></td><td><?=e($n['name'])?></td><td><?=intval($n['concurrency_limit'])?></td><td><?=$n['online']?'在线':'离线'?></td><td><?=date('Y-m-d H:i:s', intval($n['last_seen']))?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <?php else: ?><p class="empty">暂无邮件节点</p><?php endif; ?>
</div>
