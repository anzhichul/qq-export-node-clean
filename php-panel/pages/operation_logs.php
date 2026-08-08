<?php
if (!is_admin()) { echo '<div class="empty">仅管理员可访问</div>'; return; }

function op_log_label($a) {
  $map = [
    'login' => '登录', 'logout' => '退出', 'register' => '注册',
    'redeem_card' => '激活卡密', 'generate_cards' => '生成卡密',
    'update_user' => '编辑用户', 'ban_user' => '封禁用户', 'unban_user' => '解封用户',
    'create_account' => '创建账号', 'delete_account' => '删除账号', 'force_delete_account' => '强制删除账号',
    'delete_node' => '删除节点', 'user_bind_account' => '用户绑定账号', 'user_delete_account' => '用户删除账号',
    'user_create_mail_job' => '创建发信任务',
    'generate_device_key' => '生成设备密钥', 'revoke_device_key' => '吊销设备密钥',
  ];
  return $map[$a] ?? $a;
}

function op_log_url($p, $q, $act) {
  $qs = ['page' => 'operation_logs', 'p' => $p];
  if ($act !== '') $qs['action'] = $act;
  if ($q !== '') $qs['q'] = $q;
  return '?' . http_build_query($qs);
}

try {
  $perPage = 20;
  $q = trim($_GET['q'] ?? '');
  $act = trim($_GET['action'] ?? '');
  $pageNum = max(1, intval($_GET['p'] ?? 1));

  $where = '1';
  $params = [];
  if ($act !== '') { $where .= ' AND action=?'; $params[] = $act; }
  if ($q !== '') {
    $where .= ' AND (actor_username LIKE ? OR details LIKE ? OR target_id LIKE ?)';
    $p = '%' . $q . '%'; $params[] = $p; $params[] = $p; $params[] = $p;
  }

  $countStmt = db()->prepare("SELECT COUNT(*) FROM operation_logs WHERE $where");
  $countStmt->execute($params);
  $total = intval($countStmt->fetchColumn());
  $totalPages = max(1, intval(ceil($total / $perPage)));
  if ($pageNum > $totalPages) $pageNum = $totalPages;
  $offset = ($pageNum - 1) * $perPage;

  $stmt = db()->prepare("SELECT * FROM operation_logs WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
  foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
  $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $logs = $stmt->fetchAll();

  $actionOptions = db()->query('SELECT DISTINCT action FROM operation_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">操作日志读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }

// build page number set: 1 2 3 ... last two
$navPages = [];
if ($totalPages <= 6) {
  for ($i = 1; $i <= $totalPages; $i++) $navPages[] = $i;
} else {
  $navPages = [1, 2, 3];
  $navPages[] = '...';
  $navPages[] = $totalPages - 1;
  $navPages[] = $totalPages;
}
?>
<h2 class="page-title">操作日志</h2>

<div class="card">
  <div class="list-toolbar">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="color:#6d7c8d;font-size:13px">共 <?=$total?> 条</span>
      <select id="actionSel" onchange="location.href='?page=operation_logs&action='+encodeURIComponent(this.value)" style="height:34px;border:1px solid #d8dee6;border-radius:9px;padding:0 10px;font-size:12px;background:#fff">
        <option value="">全部动作</option>
        <?php foreach ($actionOptions as $opt): ?><option value="<?=e($opt)?>" <?=$opt===$act?'selected':''?>><?=e(op_log_label($opt))?>（<?=e($opt)?>）</option><?php endforeach; ?>
      </select>
    </div>
    <form method="get" class="toolbar-search">
      <input type="hidden" name="page" value="operation_logs">
      <?php if ($act !== ''): ?><input type="hidden" name="action" value="<?=e($act)?>"><?php endif; ?>
      <span class="search-field"><span class="search-icon" aria-hidden="true"></span><input name="q" value="<?=e($q)?>" placeholder="搜索操作者 / 详情 / 对象"></span>
      <?php if ($q !== ''): ?><a class="search-clear" href="?page=operation_logs<?=$act!==''?'&action='.urlencode($act):''?>">清除</a><?php endif; ?>
      <button class="btn toolbar-search-btn">搜索</button>
      <a href="?page=operation_logs" class="btn btn-sm btn-gray" style="width:auto">刷新</a>
    </form>
  </div>

  <div class="table-wrap" style="-webkit-overflow-scrolling:touch">
  <table style="width:860px;font-size:12px">
    <thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>对象</th><th>详情</th></tr></thead>
    <tbody>
    <?php if ($logs): foreach ($logs as $log): ?>
    <tr>
      <td style="white-space:nowrap;color:#6d7c8d"><?=date('Y-m-d H:i:s', intval($log['created_at']))?></td>
      <td><b><?=e($log['actor_username'] ?: '-')?></b><?php if ($log['actor_role']): ?><br><small style="color:#94a3b8"><?=e($log['actor_role'])?></small><?php endif; ?></td>
      <td><span class="badge" style="background:#edf5ff;color:#1769e0;white-space:nowrap"><?=e(op_log_label($log['action']))?></span></td>
      <td><?=e($log['target_type'] ?: '-')?><?=$log['target_id']!==''?' '.e($log['target_id']):''?></td>
      <td style="color:#475569;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=e($log['details'] ?: '-')?></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="5"><p class="empty"><?=$q!==''||$act!==''?'没有匹配的日志':'暂无操作日志'?></p></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:14px;flex-wrap:wrap">
    <a href="<?=e(op_log_url(max(1,$pageNum-1),$q,$act))?>" class="btn btn-sm btn-gray <?=$pageNum<=1?'disabled':''?>" style="width:auto;opacity:<?=$pageNum<=1?'.5':'1'?>">上一页</a>
    <?php foreach ($navPages as $np): ?>
      <?php if ($np === '...'): ?><span style="color:#94a3b8;padding:0 2px">…</span>
      <?php else: ?><a href="<?=e(op_log_url($np,$q,$act))?>" style="min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;padding:0 6px;<?=$np===$pageNum?'background:#1769e0;color:#fff;font-weight:600':'background:#f1f5f9;color:#334155'?>"><?=$np?></a><?php endif; ?>
    <?php endforeach; ?>
    <a href="<?=e(op_log_url(min($totalPages,$pageNum+1),$q,$act))?>" class="btn btn-sm btn-gray <?=$pageNum>=$totalPages?'disabled':''?>" style="width:auto;opacity:<?=$pageNum>=$totalPages?'.5':'1'?>">下一页</a>
    <form method="get" style="display:inline-flex;align-items:center;gap:5px;margin-left:6px">
      <input type="hidden" name="page" value="operation_logs">
      <?php if ($act !== ''): ?><input type="hidden" name="action" value="<?=e($act)?>"><?php endif; ?>
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?=e($q)?>"><?php endif; ?>
      <span style="font-size:12px;color:#6d7c8d">跳转</span>
      <input type="number" name="p" min="1" max="<?=$totalPages?>" value="<?=$pageNum?>" style="width:64px;height:32px;border:1px solid #d8dee6;border-radius:8px;text-align:center;font-size:12px">
      <button type="submit" class="btn btn-sm btn-gray" style="width:auto">页</button>
    </form>
  </div>
  <?php endif; ?>
</div>
