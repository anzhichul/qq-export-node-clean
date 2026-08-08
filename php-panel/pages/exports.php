<?php
$message = flash_get('message');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_export') {
  $id = intval($_POST['id'] ?? 0);
  try {
    if ($id <= 0) throw new Exception('缺少记录 ID');
    try { node_api('DELETE', '/api/exports/' . $id); } catch (Exception $e) {}
    db()->prepare('DELETE FROM export_records WHERE id=?')->execute([$id]);
    $message = '导出记录已删除';
    log_operation('delete_export', 'export', strval($id), '后台删除导出记录');
  } catch (Exception $e) { $error = $e->getMessage(); }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=exports'));
  exit;
}

$page_num = max(1, intval($_GET['p'] ?? 1));
$per_page = 30;
$offset = ($page_num - 1) * $per_page;
try {
  $total = db()->query('SELECT COUNT(*) c FROM export_records')->fetch()['c'];
  $exports = db()->query('SELECT er.*,ua.username owner_username FROM export_records er LEFT JOIN user_accounts ua ON ua.account_uin=er.account_uin ORDER BY er.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset)->fetchAll();
} catch (Exception $e) { echo '<p style="color:#c73d3d">' . e($e->getMessage()) . '</p>'; return; }
$total_pages = max(1, intval(ceil($total / $per_page)));
?>
<h2 class="page-title">导出记录</h2>
<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>
<div class="card">
<?php if ($exports): ?>
<div class="table-wrap" style="-webkit-overflow-scrolling:touch">
<table style="width:860px;font-size:12px"><thead><tr><th>账号</th><th>类型</th><th>导出数量</th><th>归属用户</th><th>操作人</th><th>时间</th><th>操作</th></tr></thead>
<tbody><?php foreach ($exports as $r): ?>
<tr>
  <td style="font-family:monospace"><?=e($r['account_uin'])?></td>
  <td><?=$r['export_type'] === 'friends' ? '好友' : ($r['export_type'] === 'members' ? '群成员' : e($r['export_type']))?></td>
  <td><?=intval($r['line_count'])?> 人</td>
  <td><?=e($r['owner_username'] ?: '-')?></td>
  <td><?=e($r['created_by'])?></td>
  <td style="white-space:nowrap;color:#6d7c8d"><?=date('Y-m-d H:i', intval($r['created_at']))?></td>
  <td><form method="post" style="display:inline-flex" onsubmit="return confirm('确定删除该导出记录和对应文件吗？删除后无法恢复。');"><input type="hidden" name="action" value="delete_export"><input type="hidden" name="id" value="<?=intval($r['id'])?>"><button type="submit" class="btn btn-sm btn-red">删除</button></form></td>
</tr>
<?php endforeach; ?></tbody></table>
</div>
<?php render_pager($page_num, $total_pages, ['page' => 'exports']); ?>
<p style="margin-top:12px;font-size:12px;color:#6d7c8d">共 <?=$total?> 条记录</p>
<?php else: ?><p class="empty">暂无导出记录</p><?php endif; ?>
</div>
