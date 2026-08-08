<?php
if (!is_admin()) { echo '<div class="empty">仅管理员可访问</div>'; return; }

$message = flash_get('message');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $id = intval($_POST['id'] ?? 0);
  $action = strval($_POST['action']);
  try {
    if ($id <= 0) throw new Exception('缺少用户 ID');
    if ($action === 'update') {
      $displayName = trim($_POST['display_name'] ?? '');
      $balance = max(0, intval($_POST['balance_points'] ?? 0));
      $expRaw = trim($_POST['membership_expires_at'] ?? '');
      $exp = 0;
      if ($expRaw !== '') {
        $t = strtotime($expRaw . ' 23:59:59');
        if ($t === false) throw new Exception('到期时间格式不正确');
        $exp = $t;
      }
      $role = in_array($_POST['role'] ?? '', ['user', 'admin'], true) ? $_POST['role'] : 'user';
      $maxAcct = max(1, min(1000, intval($_POST['max_accounts'] ?? 10)));
      $maxOnline = max(1, min(1000, intval($_POST['max_online_accounts'] ?? 2)));
      db()->prepare('UPDATE users SET display_name=?, balance_points=?, membership_expires_at=?, role=?, max_accounts=?, max_online_accounts=? WHERE id=?')
        ->execute([$displayName, $balance, $exp, $role, $maxAcct, $maxOnline, $id]);
      $message = '用户已更新';
      log_operation('update_user', 'user', strval($id), '管理员编辑用户：显示名/点数/会员到期/角色');
    } elseif ($action === 'ban') {
      $upd = db()->prepare("UPDATE users SET status='banned' WHERE id=? AND role<>'admin'");
      $upd->execute([$id]);
      if ($upd->rowCount() === 0) throw new Exception('不能封禁管理员账号');
      $message = '用户已封禁';
      log_operation('ban_user', 'user', strval($id), '管理员封禁用户');
    } elseif ($action === 'unban') {
      db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
      $message = '用户已解封';
      log_operation('unban_user', 'user', strval($id), '管理员解封用户');
    } else {
      throw new Exception('未知操作');
    }
  } catch (Exception $e) { $error = $e->getMessage(); }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=users'));
  exit;
}

$q = trim($_GET['q'] ?? '');
$perPage = 20;
$pageNum = max(1, intval($_GET['p'] ?? 1));
$where = '1';
$params = [];
if ($q !== '') {
  $where .= ' AND (u.username LIKE ? OR u.display_name LIKE ?)';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}
$countStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE $where");
$countStmt->execute($params);
$total = intval($countStmt->fetchColumn());
$totalPages = max(1, intval(ceil($total / $perPage)));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $perPage;

$sql = 'SELECT u.id,u.username,u.display_name,u.role,u.status,u.balance_points,u.membership_expires_at,u.max_accounts,u.max_online_accounts,u.last_login_at,u.created_at,(SELECT COUNT(*) FROM user_accounts ua WHERE ua.user_id=u.id) AS owned_accounts FROM users u WHERE ' . $where;
$sql .= ' ORDER BY u.id DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset);
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$now = time();
?>
<h2 class="page-title">用户管理</h2>

<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<div class="card">
  <div class="list-toolbar">
    <span style="color:#6d7c8d;font-size:13px">共 <?=$total?> 个用户<?=$q!==''?'，搜索「'.e($q).'」':''?></span>
    <form class="toolbar-search" method="get">
      <input type="hidden" name="page" value="users">
      <span class="search-field"><input name="q" value="<?=e($q)?>" placeholder="搜索用户名 / 显示名称"><i class="search-icon"></i></span>
      <button type="submit" class="btn btn-sm toolbar-search-btn">搜索</button>
      <?php if ($q !== ''): ?><a class="search-clear" href="?page=users">清除</a><?php endif; ?>
    </form>
  </div>

  <div class="table-wrap" style="-webkit-overflow-scrolling:touch">
  <table style="width:1200px;font-size:12px;white-space:nowrap">
    <thead><tr>
      <th>ID</th><th>用户名</th><th>显示名称</th><th>角色</th><th>状态</th><th>点数</th><th>账号数</th><th>上限(在线/总数)</th><th>会员到期</th><th>上次登录</th><th>注册时间</th><th>操作</th>
    </tr></thead>
    <tbody>
    <?php if ($users): foreach ($users as $u):
      $uid = intval($u['id']);
      $isAdmin = $u['role'] === 'admin';
      $banned = $u['status'] === 'banned';
      $exp = intval($u['membership_expires_at'] ?? 0);
      if ($exp <= 0) { $expLabel = '<span style="color:#94a3b8">未激活</span>'; $expColor = '#94a3b8'; }
      elseif ($exp > $now) { $expLabel = '<span style="color:#1d7a42">会员中</span><br><small style="color:#6d7c8d">' . date('Y-m-d H:i', $exp) . '</small>'; }
      else { $expLabel = '<span style="color:#c73d3d">已到期</span><br><small style="color:#6d7c8d">' . date('Y-m-d H:i', $exp) . '</small>'; }
    ?>
    <tr>
      <td><?=$uid?></td>
      <td><b><?=e($u['username'])?></b></td>
      <td><?=e($u['display_name'] ?: '-')?></td>
      <td><?=$isAdmin?'<span class="badge" style="background:#edf5ff;color:#1769e0">管理员</span>':'用户'?></td>
      <td><?=$banned?'<span style="color:#c73d3d;font-weight:600">已封禁</span>':($u['status']==='active'?'<span style="color:#1d7a42">正常</span>':e($u['status']?:'active'))?></td>
      <td><?=intval($u['balance_points'] ?? 0)?></td>
      <td><?=intval($u['owned_accounts'] ?? 0)?></td>
      <td><?=intval($u['max_online_accounts'] ?? 2)?> / <?=intval($u['max_accounts'] ?? 10)?></td>
      <td><?=$expLabel?></td>
      <td><?=!empty($u['last_login_at']) ? date('Y-m-d H:i:s', intval($u['last_login_at'])) : '-'?></td>
      <td><?=date('Y-m-d H:i:s', intval($u['created_at']))?></td>
      <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <button type="button" class="btn btn-sm btn-gray"
            data-id="<?=$uid?>"
            data-username="<?=e($u['username'])?>"
            data-display="<?=e($u['display_name'])?>"
            data-balance="<?=intval($u['balance_points'])?>"
            data-exp="<?=$exp>0?date('Y-m-d', $exp):''?>"
            data-role="<?=e($u['role'])?>"
            data-maxacct="<?=intval($u['max_accounts'] ?? 10)?>"
            data-maxonline="<?=intval($u['max_online_accounts'] ?? 2)?>"
            onclick="openEdit(this)">编辑</button>
          <?php if ($banned): ?>
            <form method="post" style="display:inline-flex"><input type="hidden" name="action" value="unban"><input type="hidden" name="id" value="<?=$uid?>"><button type="submit" class="btn btn-sm btn-gray">解封</button></form>
          <?php elseif (!$isAdmin): ?>
            <form method="post" style="display:inline-flex" onsubmit="return confirm('确定封禁用户 <?=e($u['username'])?> 吗？封禁后该用户无法登录。');"><input type="hidden" name="action" value="ban"><input type="hidden" name="id" value="<?=$uid?>"><button type="submit" class="btn btn-sm btn-red">封禁</button></form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="12" class="empty"><?=$q!==''?'没有匹配的用户':'暂无用户'?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php render_pager($pageNum, $totalPages, ['page' => 'users', 'q' => $q]); ?>
</div>

<div class="modal" id="editModal">
  <div class="modal-box">
    <h2 id="editTitle">编辑用户</h2>
    <form method="post" id="editForm">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="editId">
      <div class="form-group"><label>用户名</label><input id="editUsername" readonly style="background:#f1f5f9"></div>
      <div class="form-group"><label>显示名称</label><input name="display_name" id="editDisplay" required></div>
      <div class="form-group"><label>点数</label><input name="balance_points" id="editBalance" type="number" min="0" required></div>
      <div class="form-group"><label>会员到期时间（留空为未激活）</label><input name="membership_expires_at" id="editExp" type="date"></div>
      <div class="form-group"><label>角色</label>
        <select name="role" id="editRole">
          <option value="user">普通用户</option>
          <option value="admin">管理员</option>
        </select>
      </div>
      <div class="form-group"><label>最大账户总数</label><input name="max_accounts" id="editMaxAcct" type="number" min="1" max="1000" value="10"></div>
      <div class="form-group"><label>最大同时在线账户数</label><input name="max_online_accounts" id="editMaxOnline" type="number" min="1" max="1000" value="2"></div>
      <div class="modal-actions" style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
        <button type="button" class="btn btn-gray" onclick="closeEdit()">取消</button>
        <button type="submit" class="btn">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(btn) {
  document.getElementById('editId').value = btn.getAttribute('data-id');
  document.getElementById('editUsername').value = btn.getAttribute('data-username');
  document.getElementById('editDisplay').value = btn.getAttribute('data-display');
  document.getElementById('editBalance').value = btn.getAttribute('data-balance');
  document.getElementById('editExp').value = btn.getAttribute('data-exp');
  document.getElementById('editRole').value = btn.getAttribute('data-role');
  document.getElementById('editMaxAcct').value = btn.getAttribute('data-maxacct');
  document.getElementById('editMaxOnline').value = btn.getAttribute('data-maxonline');
  document.getElementById('editTitle').textContent = '编辑用户 ' + btn.getAttribute('data-username');
  document.getElementById('editModal').classList.add('show');
}
function closeEdit() { document.getElementById('editModal').classList.remove('show'); }
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) closeEdit(); });
</script>
