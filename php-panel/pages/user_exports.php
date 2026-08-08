<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }
if (empty($_SESSION['export_csrf'])) $_SESSION['export_csrf'] = bin2hex(random_bytes(24));
try {
  $perPage = 20;
  $pageNum = max(1, intval($_GET['p'] ?? 1));
  $countStmt = db()->prepare('SELECT COUNT(*) FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE ua.user_id=?');
  $countStmt->execute([$_SESSION['user_id']]);
  $total = intval($countStmt->fetchColumn());
  $totalPages = max(1, intval(ceil($total / $perPage)));
  if ($pageNum > $totalPages) $pageNum = $totalPages;
  $offset = ($pageNum - 1) * $perPage;
  $stmt = db()->prepare('SELECT er.*,g.group_name FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin LEFT JOIN groups_data g ON g.account_uin=er.account_uin AND g.group_id=er.group_id WHERE ua.user_id=? ORDER BY er.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset));
  $stmt->execute([$_SESSION['user_id']]);
  $exports = $stmt->fetchAll();
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">导出记录读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }
?>
<h2 class="page-title">我的导出</h2>
<div class="card export-list-card">
<?php if ($exports): ?>
<div class="table-wrap export-table-wrap">
<table class="export-table"><thead><tr><th>QQ 账号</th><th>群聊名称</th><th>类型</th><th>导出数量</th><th>导出时间</th><th>操作</th></tr></thead>
<tbody><?php foreach ($exports as $r):
  $typeLabel = $r['export_type'] === 'friends' ? '好友' : (in_array($r['export_type'], ['members','members_filtered'], true) ? ($r['export_type'] === 'members_filtered' ? '群成员（排除管理）' : '群成员') : $r['export_type']);
  $groupName = trim(strval($r['group_name'] ?? ''));
  if ($r['export_type'] === 'friends') $groupName = '好友列表';
  elseif ($groupName === '') $groupName = strval($r['group_id'] ?: '-');
?><tr><td class="export-uin"><?=e($r['account_uin'])?></td><td><div class="export-group-name"><?=e($groupName)?></div><?php if (!empty($r['group_id'])): ?><small class="export-group-id"><?=e($r['group_id'])?></small><?php endif; ?></td><td><span class="export-type-label"><?=e($typeLabel)?></span></td><td><strong class="export-count"><?=intval($r['line_count'])?></strong><small> 条</small></td><td class="export-time"><?=date('Y-m-d', intval($r['created_at']))?><small><?=date('H:i', intval($r['created_at']))?></small></td><td><div class="export-actions"><a class="btn btn-sm btn-gray" href="/?page=api_export_download&id=<?=intval($r['id'])?>">下载</a><button type="button" class="btn btn-sm btn-gray" onclick="viewExport(<?=intval($r['id'])?>)">查看</button><button type="button" class="btn btn-sm btn-red" onclick="deleteExport(<?=intval($r['id'])?>)">删除</button></div></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php render_pager($pageNum, $totalPages, ['page' => 'user_exports']); ?>
<?php else: ?><p class="empty">你还没有导出记录</p><?php endif; ?>
</div>

<div class="modal" id="exportViewModal">
  <div class="modal-box export-view-modal">
    <h2>导出内容</h2>
    <p id="exportViewMeta">正在读取...</p>
    <textarea id="exportViewContent" rows="16" readonly></textarea>
    <div class="export-modal-actions"><button type="button" class="btn btn-gray" onclick="closeExportView()">关闭</button></div>
  </div>
</div>
<script>
var exportCsrf = <?=json_encode($_SESSION['export_csrf'])?>;
function viewExport(id) {
  document.getElementById('exportViewModal').classList.add('show');
  document.getElementById('exportViewMeta').textContent = '正在读取...';
  document.getElementById('exportViewContent').value = '';
  fetch('/?page=api_export_content&id=' + encodeURIComponent(id) + '&t=' + Date.now())
    .then(function(r){return r.json()}).then(function(data){
      if (!data.ok) { document.getElementById('exportViewMeta').textContent = data.error || '读取失败'; return; }
      document.getElementById('exportViewMeta').textContent = '共 ' + (data.line_count || 0) + ' 条';
      document.getElementById('exportViewContent').value = data.content || '';
    }).catch(function(){ document.getElementById('exportViewMeta').textContent = '读取失败'; });
}
function closeExportView() { document.getElementById('exportViewModal').classList.remove('show'); }
function deleteExport(id) {
  if (!confirm('确定删除这条导出记录和对应 TXT 文件吗？删除后无法恢复。')) return;
  fetch('/?page=api_export_delete', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(exportCsrf)
  }).then(function(r){return r.json()}).then(function(data){
    if (!data.ok) { toast(data.error || '删除失败'); return; }
    toast('导出文件已删除');
    setTimeout(function(){ location.reload(); }, 600);
  }).catch(function(){ toast('请求失败'); });
}
</script>
