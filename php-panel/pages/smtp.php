<?php
$message = flash_get('message');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (($_POST['action'] ?? '') === 'add_group') {
      $name = trim($_POST['group_name'] ?? '');
      if ($name === '') throw new Exception('请输入分组名称');
      db()->prepare('INSERT INTO smtp_groups(name,owner_user_id,owner_username,owner_role,created_at) VALUES(?,?,?,?,?)')
        ->execute([mb_substr($name, 0, 100), intval($_SESSION['user_id']), $_SESSION['username'] ?? 'admin_root', 'admin', time()]);
      $message = '分组已创建';
      log_operation('create_smtp_group', 'smtp_group', strval(db()->lastInsertId()), '管理员创建 SMTP 分组');
    }
    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
      $id = $_POST['id'] ?? '';
      $host = trim($_POST['host'] ?? '');
      $port = intval($_POST['port'] ?: 587);
      $user = $_POST['user'] ?? '';
      $pass = $_POST['pass'] ?? '';
      $from_email = trim($_POST['from_email'] ?? '');
      $from_name = $_POST['from_name'] ?? '';
      $sec = $_POST['security'] ?? 'starttls';
      $notes = $_POST['notes'] ?? '';
      $groupId = max(0, intval($_POST['group_id'] ?? 0));
      if (!$host || !$from_email) throw new Exception('服务器和发件人邮箱必填');
      $body = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
        'from_email' => $from_email, 'from_name' => $from_name, 'notes' => $notes,
        'group_id' => $groupId,
        'use_ssl' => $sec === 'ssl' ? 1 : 0, 'use_starttls' => $sec === 'starttls' ? 1 : 0,
        'owner_user_id' => intval($_SESSION['user_id'] ?? 0), 'owner_username' => $_SESSION['username'] ?? 'admin_root', 'owner_role' => 'admin'];
      if ($_POST['action'] === 'add') {
        node_api('POST', '/api/smtp/configs', $body);
        $message = '配置已添加';
      } else {
        if (!$pass) unset($body['pass']);
        node_api('PUT', '/api/smtp/configs/' . urlencode($id), $body);
        $message = '配置已更新';
      }
    }
    if ($_POST['action'] === 'delete') {
      node_api('DELETE', '/api/smtp/configs/' . urlencode($_POST['id']), ['owner_role' => 'admin']);
      $message = '配置已删除';
    }
    if ($message === '' && ($_POST['action'] ?? '') === 'add_group') { $message = '分组已创建'; }
  } catch (Exception $e) { $error = $e->getMessage(); }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=smtp'));
  exit;
}

try {
  $groups = db()->query('SELECT g.*,(SELECT COUNT(*) FROM smtp_configs c WHERE c.group_id=g.id) config_count FROM smtp_groups g ORDER BY g.created_at,g.id')->fetchAll();
  $configs = node_api('GET', '/api/smtp/configs')['configs'] ?? [];
  $configsByGroup = [];
  foreach ($configs as $c) $configsByGroup[intval($c['group_id'] ?? 0)][] = $c;
  $groupMap = [];
  foreach ($groups as $g) $groupMap[intval($g['id'])] = $g['name'];
} catch (Exception $e) { echo '<p style="color:#c73d3d">' . e($e->getMessage()) . '</p>'; return; }

$editId = $_GET['edit'] ?? '';
$editConfig = null;
if ($editId) foreach ($configs as $c) { if ($c['id'] === $editId) { $editConfig = $c; break; } }

$me = $_SESSION['username'] ?? 'admin_root';
?>
<h2 class="page-title">SMTP 配置</h2>

<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<?php if ($editConfig || isset($_GET['new'])): $c = $editConfig; ?>
<div class="card">
<h3><?=$c?'编辑配置':'新增配置'?></h3>
<form method="post">
  <input type="hidden" name="action" value="<?=$c?'edit':'add'?>">
  <?php if ($c): ?><input type="hidden" name="id" value="<?=e($c['id'])?>"><?php endif; ?>
  <div class="form-group"><label>SMTP 服务器</label><input name="host" value="<?=e($c['host']??'')?>" placeholder="smtp.example.com" required></div>
  <div class="form-row">
    <div class="form-group"><label>端口</label><input name="port" id="adminSmtpPort" value="<?=e($c['port']??'587')?>"></div>
    <div class="form-group"><label>加密方式</label>
      <select name="security" id="adminSmtpSecurity" onchange="updateAdminSmtpPort()">
        <option value="starttls" <?=($c&&!$c['use_ssl']&&$c['use_starttls'])||!$c?'selected':''?>>STARTTLS (587)</option>
        <option value="ssl" <?=$c&&$c['use_ssl']?'selected':''?>>SSL/TLS (465)</option>
        <option value="none" <?=$c&&!$c['use_ssl']&&!$c['use_starttls']?'selected':''?>>无加密 (25)</option>
      </select></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label>用户名（可选）</label><input name="user" value="<?=e($c['user']??'')?>"></div>
    <div class="form-group"><label>密码<?=$c?'（留空不修改）':''?></label><input type="password" name="pass"></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label>发件人邮箱</label><input name="from_email" value="<?=e($c['from_email']??'')?>" required></div>
    <div class="form-group"><label>发件人名称</label><input name="from_name" value="<?=e($c['from_name']??'')?>"></div>
  </div>
  <div class="form-group"><label>备注</label><input name="notes" value="<?=e($c['notes']??'')?>"></div>
  <div class="form-group"><label>所属分组</label>
    <select name="group_id">
      <option value="0">未分组</option>
      <?php foreach ($groups as $g): ?><option value="<?=intval($g['id'])?>" <?=$c&&intval($c['group_id']??0)===intval($g['id'])?'selected':''?>><?=e($g['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <p style="color:#6d7c8d;font-size:12px;margin-bottom:10px">归属：当前管理员（<?=e($me)?>）</p>
  <button type="submit" class="btn">保存</button>
  <a href="?page=smtp" class="btn btn-gray">取消</a>
</form>
</div>
<?php endif; ?>

<?php if (!isset($_GET['new']) && !$editId): ?>
<div class="card">
  <div class="smtp-group-heading"><div><h3>邮箱分组</h3><small>显示所有分组及创建归属人，任务发送时随机使用所选组内的邮箱</small></div><div style="display:flex;gap:6px"><button type="button" class="btn btn-sm" onclick="openGroupModal()">+ 创建分组</button><a href="?page=smtp&new=1" class="btn btn-sm btn-gray">+ 新增配置</a></div></div>
  <div class="smtp-group-list">
  <?php foreach ($groups as $g): $gc = $configsByGroup[intval($g['id'])] ?? []; ?>
    <details class="smtp-group-block">
      <summary><span class="smtp-group-chevron"></span><span class="smtp-group-summary"><strong><?=e($g['name'])?></strong><small><?=intval($g['config_count'])?> 个邮箱 · 创建人：<?=e(($g['owner_role'] ?? '')==='admin'?'管理员':'')?><?=e($g['owner_username'] ?: '-')?></small></span><span class="smtp-group-open-text">查看邮箱</span></summary>
      <div class="smtp-group-content">
        <?php if ($gc): foreach ($gc as $c): ?>
          <div class="smtp-email-row">
            <div class="smtp-email-identity"><strong><?=e($c['from_email'])?></strong><span><?=e($c['from_name'] ?: $c['user'] ?: '未设置发件人名称')?></span></div>
            <div class="smtp-email-meta"><span><?=e($c['host'])?>:<?=intval($c['port'])?></span><small><?=$c['use_ssl']?'SSL':($c['use_starttls']?'STARTTLS':'无加密')?></small></div>
            <div class="smtp-actions">
              <a href="?page=smtp&edit=<?=urlencode($c['id'])?>" class="btn btn-sm btn-gray">编辑</a>
              <form method="post" onsubmit="return confirm('确定删除这个邮箱？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn btn-sm btn-red">删除</button></form>
            </div>
          </div>
        <?php endforeach; else: ?><p class="smtp-group-empty">这个分组还没有邮箱。</p><?php endif; ?>
        <div class="smtp-group-footer"><a href="?page=smtp&new=1" class="btn btn-sm">+ 添加邮箱</a></div>
      </div>
    </details>
  <?php endforeach; ?>
  <?php $ungrouped = $configsByGroup[0] ?? []; if ($ungrouped): ?>
    <details class="smtp-group-block">
      <summary><span class="smtp-group-chevron"></span><span class="smtp-group-summary"><strong>未分组</strong><small><?=count($ungrouped)?> 个邮箱</small></span><span class="smtp-group-open-text">查看邮箱</span></summary>
      <div class="smtp-group-content"><?php foreach ($ungrouped as $c): ?>
        <div class="smtp-email-row"><div class="smtp-email-identity"><strong><?=e($c['from_email'])?></strong><span><?=e($c['from_name'] ?: $c['user'] ?: '未设置发件人名称')?></span></div><div class="smtp-email-meta"><span><?=e($c['host'])?>:<?=intval($c['port'])?></span><small><?=$c['use_ssl']?'SSL':($c['use_starttls']?'STARTTLS':'无加密')?></small></div><div class="smtp-actions"><a href="?page=smtp&edit=<?=urlencode($c['id'])?>" class="btn btn-sm btn-gray">编辑</a><form method="post" onsubmit="return confirm('确定删除这个邮箱？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn btn-sm btn-red">删除</button></form></div></div>
      <?php endforeach; ?></div>
    </details>
  <?php endif; ?>
  <?php if (!$groups && !$ungrouped): ?><p class="empty">暂无 SMTP 分组和配置，请先创建分组或新增配置</p><?php endif; ?>
  </div>
</div>

<div class="modal" id="groupModal" onclick="if(event.target===this)closeGroupModal()">
  <div class="modal-box smtp-group-modal" role="dialog" aria-modal="true" aria-labelledby="groupModalTitle">
    <h2 id="groupModalTitle">创建邮箱分组</h2>
    <p>创建后归属当前管理员（<?=e($me)?>），可在编辑邮箱时加入邮箱。</p>
    <form method="post">
      <input type="hidden" name="action" value="add_group">
      <div class="form-group"><label for="groupNameInput">分组名称</label><input id="groupNameInput" name="group_name" maxlength="100" placeholder="例如：默认发信组" autocomplete="off" required></div>
      <div class="smtp-group-modal-actions"><button type="button" class="btn btn-gray" onclick="closeGroupModal()">取消</button><button class="btn" type="submit">保存分组</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function updateAdminSmtpPort() {
  var security = document.getElementById('adminSmtpSecurity');
  var port = document.getElementById('adminSmtpPort');
  if (!security || !port) return;
  port.value = security.value === 'ssl' ? '465' : (security.value === 'starttls' ? '587' : '25');
}
function openGroupModal() {
  var modal = document.getElementById('groupModal');
  if (!modal) return;
  modal.classList.add('show');
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ document.getElementById('groupNameInput').focus(); }, 30);
}
function closeGroupModal() {
  var modal = document.getElementById('groupModal');
  if (!modal) return;
  modal.classList.remove('show');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(event) { if (event.key === 'Escape') closeGroupModal(); });
</script>
