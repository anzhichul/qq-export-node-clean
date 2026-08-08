<?php
try {
  $mailNodes = node_api('GET', '/api/mail/nodes')['nodes'] ?? [];
} catch (Exception $e) {
  echo '<div class="card"><p style="color:#c73d3d">邮件节点数据读取失败: ' . e($e->getMessage()) . '</p></div>';
  return;
}
?>
<h2 class="page-title">发信节点</h2>

<p style="color:#6d7c8d;margin-bottom:10px">这里列出正在工作的发信节点（用户安装的 Android 发信 App）。独立 Linux 发信节点已停用，不再需要部署。</p>

<div class="card">
  <h3>节点列表</h3>
  <?php if ($mailNodes): ?>
  <div class="table-wrap">
  <table><thead><tr><th>节点 ID</th><th>名称</th><th>并发</th><th>状态</th><th>最后心跳</th></tr></thead>
  <tbody><?php foreach ($mailNodes as $n): ?><tr><td><?=e($n['node_id'])?></td><td><?=e($n['name'])?></td><td><?=intval($n['concurrency_limit'])?></td><td><?=$n['online']?'在线':'离线'?></td><td><?=date('Y-m-d H:i:s', intval($n['last_seen']))?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <?php else: ?><p class="empty">暂无发信节点</p><?php endif; ?>
</div>
