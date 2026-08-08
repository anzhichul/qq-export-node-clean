<?php
function mail_status_label($s) {
  $map = ['pending' => '待发送', 'running' => '发送中', 'done' => '已完成', 'partial' => '部分失败', 'cancelled' => '已取消'];
  $color = ['pending' => '#1769e0', 'running' => '#b66b08', 'done' => '#16824b', 'partial' => '#b83232', 'cancelled' => '#788797'];
  return '<span style="color:' . ($color[$s] ?? '#788797') . ';font-weight:600">' . ($map[$s] ?? $s) . '</span>';
}

$q = trim($_GET['q'] ?? '');
$perPage = 20;
$pageNum = max(1, intval($_GET['p'] ?? 1));
$where = '1';
$params = [];
if ($q !== '') {
  $where .= ' AND (created_by LIKE ? OR subject LIKE ? OR id LIKE ?)';
  $p = '%' . $q . '%'; $params[] = $p; $params[] = $p; $params[] = $p;
}
try {
  $countStmt = db()->prepare("SELECT COUNT(*) FROM mail_jobs WHERE $where");
  $countStmt->execute($params);
  $total = intval($countStmt->fetchColumn());
  $totalPages = max(1, intval(ceil($total / $perPage)));
  if ($pageNum > $totalPages) $pageNum = $totalPages;
  $offset = ($pageNum - 1) * $perPage;
  $sql = "SELECT id,subject,recipient_count,sent_count,failed_count,status,created_by,owner_user_id,created_at,task_key,task_key_expires_at FROM mail_jobs WHERE $where ORDER BY created_at DESC LIMIT " . intval($perPage) . ' OFFSET ' . intval($offset);
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $jobs = $stmt->fetchAll();
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">发信记录读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }
?>
<h2 class="page-title">发信记录</h2>

<div class="card">
  <div class="list-toolbar">
    <span style="color:#6d7c8d;font-size:13px">共 <?=$total?> 条发信任务</span>
    <form method="get" class="toolbar-search">
      <input type="hidden" name="page" value="smtp_logs">
      <span class="search-field"><span class="search-icon" aria-hidden="true"></span><input name="q" value="<?=e($q)?>" placeholder="搜索创建者 / 主题 / 任务ID"></span>
      <?php if ($q !== ''): ?><a class="search-clear" href="?page=smtp_logs">清除</a><?php endif; ?>
      <button class="btn toolbar-search-btn">搜索</button>
    </form>
  </div>
  <?php if ($jobs): ?>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch">
  <table style="width:980px;font-size:12px">
    <thead><tr><th>归属用户</th><th>主题</th><th>收件人</th><th>已发送</th><th>失败</th><th>状态</th><th>创建时间</th><th>任务密钥</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
    <tr>
      <td><b><?=e($j['created_by'] ?: '-')?></b></td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=e($j['subject'] ?: '-')?></td>
      <td><?=intval($j['recipient_count'])?></td>
      <td style="color:#16824b"><?=intval($j['sent_count'])?></td>
      <td style="color:<?=intval($j['failed_count'])>0?'#b83232':'#94a3b8'?>"><?=intval($j['failed_count'])?></td>
      <td><?=mail_status_label($j['status'])?></td>
      <td style="white-space:nowrap;color:#6d7c8d"><?=date('Y-m-d H:i', intval($j['created_at']))?></td>
      <td><?=$j['task_key']!==''?'<code style="font-size:11px">'.e($j['task_key']).'</code>':'-'?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php render_pager($pageNum, $totalPages, ['page' => 'smtp_logs', 'q' => $q]); ?>
  <?php else: ?><p class="empty"><?=$q!==''?'没有匹配的发信记录':'暂无发信记录'?></p><?php endif; ?>
</div>
