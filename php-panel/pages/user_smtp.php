<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

function smtp_defaults_for_email($email) {
  $domain = strtolower(substr(strrchr(strval($email), '@') ?: '', 1));
  $providers = [
    'qq.com' => ['smtp.qq.com', 465, 'ssl'],
    'foxmail.com' => ['smtp.qq.com', 465, 'ssl'],
    '163.com' => ['smtp.163.com', 465, 'ssl'],
    '126.com' => ['smtp.126.com', 465, 'ssl'],
    'yeah.net' => ['smtp.yeah.net', 465, 'ssl'],
    'sina.com' => ['smtp.sina.com', 465, 'ssl'],
    'sina.cn' => ['smtp.sina.com', 465, 'ssl'],
    'aliyun.com' => ['smtp.aliyun.com', 465, 'ssl'],
    'gmail.com' => ['smtp.gmail.com', 465, 'ssl'],
    'outlook.com' => ['smtp-mail.outlook.com', 587, 'starttls'],
    'hotmail.com' => ['smtp-mail.outlook.com', 587, 'starttls'],
    'live.com' => ['smtp-mail.outlook.com', 587, 'starttls'],
  ];
  return $providers[$domain] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (($_POST['action'] ?? '') === 'add_group') {
      $name = trim($_POST['group_name'] ?? '');
      if ($name === '') throw new Exception('请输入分组名称');
      db()->prepare('INSERT INTO smtp_groups(name,owner_user_id,owner_username,owner_role,created_at) VALUES(?,?,?,?,?)')
        ->execute([mb_substr($name, 0, 100), intval($_SESSION['user_id']), $_SESSION['username'], 'user', time()]);
      echo '<script>alert("分组已创建");location.href="user_smtp.php";</script>'; exit;
    }
    if (($_POST['action'] ?? '') === 'delete_group') {
      $groupId = intval($_POST['group_id'] ?? 0);
      $owner = db()->prepare('SELECT id FROM smtp_groups WHERE id=? AND owner_user_id=? AND owner_role=?');
      $owner->execute([$groupId, intval($_SESSION['user_id']), 'user']);
      if (!$owner->fetch()) throw new Exception('分组不存在');
      db()->prepare('UPDATE smtp_configs SET group_id=0 WHERE group_id=? AND owner_user_id=?')->execute([$groupId, intval($_SESSION['user_id'])]);
      db()->prepare('DELETE FROM smtp_groups WHERE id=? AND owner_user_id=?')->execute([$groupId, intval($_SESSION['user_id'])]);
      echo '<script>alert("分组已删除，组内邮箱已移出");location.href="user_smtp.php";</script>'; exit;
    }
    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
      $id = $_POST['id'] ?? '';
      $from_email = strtolower(trim($_POST['from_email'] ?? ''));
      if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) throw new Exception('请输入正确的邮箱地址');
      $defaults = smtp_defaults_for_email($from_email);
      $host = trim($_POST['host'] ?? '');
      if ($host === '' && $defaults) $host = $defaults[0];
      $port = intval($_POST['port'] ?? 0);
      if ($port <= 0) $port = $defaults ? $defaults[1] : 587;
      $user = trim($_POST['user'] ?? '') ?: $from_email;
      $pass = $_POST['pass'] ?? '';
      $from_name = $_POST['from_name'] ?? '';
      $sec = $_POST['security'] ?? ($defaults ? $defaults[2] : 'starttls');
      $notes = $_POST['notes'] ?? '';
      $groupId = intval($_POST['group_id'] ?? 0);
      if (!$host) throw new Exception('未识别该邮箱，请展开高级设置填写 SMTP 服务器');
      if ($_POST['action'] === 'add' && trim($pass) === '') throw new Exception('请输入邮箱 SMTP 授权码');
      if ($groupId > 0) {
        $groupOwner = db()->prepare('SELECT id FROM smtp_groups WHERE id=? AND owner_user_id=? AND owner_role=?');
        $groupOwner->execute([$groupId, intval($_SESSION['user_id']), 'user']);
        if (!$groupOwner->fetch()) throw new Exception('邮箱分组不存在');
      }
      $body = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
        'from_email' => $from_email, 'from_name' => $from_name, 'notes' => $notes,
        'use_ssl' => $sec === 'ssl' ? 1 : 0, 'use_starttls' => $sec === 'starttls' ? 1 : 0, 'group_id' => $groupId,
        'owner_user_id' => intval($_SESSION['user_id']), 'owner_username' => $_SESSION['username'], 'owner_role' => 'user'];
      if ($_POST['action'] === 'add') {
        node_api('POST', '/api/smtp/configs', $body);
        echo '<script>location.replace("user_smtp.php")</script>'; exit;
      } else {
        if (!$pass) unset($body['pass']);
        node_api('PUT', '/api/smtp/configs/' . urlencode($id), $body);
        echo '<script>location.replace("user_smtp.php")</script>'; exit;
      }
    }
    if ($_POST['action'] === 'delete') {
      node_api('DELETE', '/api/smtp/configs/' . urlencode($_POST['id']), [
        'owner_user_id' => intval($_SESSION['user_id']), 'owner_role' => 'user'
      ]);
      echo '<script>alert("已删除");location.href="user_smtp.php";</script>'; exit;
    }
  } catch (Exception $e) { $error = $e->getMessage(); }
}

try {
  $groupStmt = db()->prepare('SELECT g.*,COUNT(c.id) config_count FROM smtp_groups g LEFT JOIN smtp_configs c ON c.group_id=g.id AND c.owner_user_id=g.owner_user_id WHERE g.owner_user_id=? AND g.owner_role=? GROUP BY g.id,g.name,g.owner_user_id,g.owner_username,g.owner_role,g.created_at ORDER BY g.created_at,g.id');
  $groupStmt->execute([intval($_SESSION['user_id']), 'user']);
  $groups = $groupStmt->fetchAll();
  $configs = node_api('GET', '/api/smtp/configs?owner_user_id=' . intval($_SESSION['user_id']) . '&owner_role=user')['configs'] ?? [];
  $configsByGroup = [];
  foreach ($configs as $config) {
    $configsByGroup[intval($config['group_id'] ?? 0)][] = $config;
  }
} catch (Exception $e) { echo '<p style="color:#c73d3d">' . e($e->getMessage()) . '</p>'; return; }

$editId = $_GET['edit'] ?? '';
$editConfig = null;
if ($editId) foreach ($configs as $c) { if ($c['id'] === $editId) { $editConfig = $c; break; } }
?>
<h2 class="page-title">我的 SMTP</h2>

<?php if ($editConfig || isset($_GET['new'])): $c = $editConfig; $selectedGroupId = intval($c['group_id'] ?? ($_GET['group_id'] ?? 0)); ?>
<div class="card">
<h3><?=$c?'编辑邮箱':'添加邮箱'?></h3>
<p style="color:#6d7c8d;font-size:13px;margin:-4px 0 16px">常用邮箱会自动配置 SMTP，只需填写邮箱和授权码。</p>
<form method="post" id="quickSmtpForm">
  <input type="hidden" name="action" value="<?=$c?'edit':'add'?>">
  <?php if ($c): ?><input type="hidden" name="id" value="<?=e($c['id'])?>"><?php endif; ?>
  <div class="form-row">
    <div class="form-group"><label>邮箱地址</label><input type="email" id="smtpEmail" name="from_email" value="<?=e($c['from_email']??'')?>" placeholder="例如 123456@qq.com" oninput="applySmtpPreset(true)" required><small id="smtpPresetHint" style="display:block;margin-top:6px;color:#6d7c8d"></small></div>
    <div class="form-group"><label>SMTP 授权码<?=$c?'（不修改可留空）':''?></label><input type="password" name="pass" placeholder="不是邮箱登录密码" <?=$c?'':'required'?>><small style="display:block;margin-top:6px;color:#6d7c8d">请在邮箱设置中开启 SMTP 后获取</small></div>
  </div>
  <div class="form-group"><label>放入分组（可选）</label><select name="group_id"><option value="0">暂不分组</option><?php foreach ($groups as $g): ?><option value="<?=intval($g['id'])?>" <?=$selectedGroupId===intval($g['id'])?'selected':''?>><?=e($g['name'])?></option><?php endforeach; ?></select></div>
  <details class="smtp-advanced" <?=$c?'open':''?>>
    <summary>高级设置（特殊企业邮箱使用）</summary>
    <div class="form-row" style="margin-top:14px">
      <div class="form-group"><label>SMTP 服务器</label><input id="smtpHost" name="host" value="<?=e($c['host']??'')?>" placeholder="自动识别"></div>
      <div class="form-group"><label>登录用户名</label><input id="smtpUser" name="user" value="<?=e($c['user']??'')?>" placeholder="默认与邮箱地址相同"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>端口</label><input id="smtpPort" name="port" value="<?=e($c['port']??'')?>" placeholder="自动识别"></div>
      <div class="form-group"><label>加密方式</label><select id="smtpSecurity" name="security" onchange="updateSmtpPort()"><option value="ssl" <?=$c&&$c['use_ssl']?'selected':''?>>SSL/TLS（465）</option><option value="starttls" <?=(!$c||(!$c['use_ssl']&&$c['use_starttls']))?'selected':''?>>STARTTLS（587）</option><option value="none" <?=$c&&!$c['use_ssl']&&!$c['use_starttls']?'selected':''?>>无加密（25）</option></select></div>
    </div>
    <div class="form-row"><div class="form-group"><label>发件人名称（可选）</label><input name="from_name" value="<?=e($c['from_name']??'')?>"></div><div class="form-group"><label>备注（可选）</label><input name="notes" value="<?=e($c['notes']??'')?>"></div></div>
  </details>
  <div style="display:flex;gap:8px;margin-top:16px"><button type="submit" class="btn">保存邮箱</button><a href="user_smtp.php" class="btn btn-gray">取消</a></div>
</form>
</div>
<?php endif; ?>

<?php if (!isset($_GET['new'])): ?>
<div class="card">
  <div class="smtp-group-heading"><div><h3>邮箱管理</h3><small>任务发送时会随机使用所选组内的邮箱</small></div><div style="display:flex;gap:6px"><a href="user_smtp.php?new=1" class="btn btn-sm">+ 添加邮箱</a><button type="button" class="btn btn-sm btn-gray" onclick="openGroupModal()">+ 创建分组</button></div></div>
  <div class="smtp-group-list">
  <?php foreach ($groups as $g): $groupConfigs = $configsByGroup[intval($g['id'])] ?? []; ?>
    <details class="smtp-group-block">
      <summary><span class="smtp-group-chevron"></span><span class="smtp-group-summary"><strong><?=e($g['name'])?></strong><small><?=count($groupConfigs)?> 个邮箱</small></span><span class="smtp-group-open-text">查看邮箱</span></summary>
      <div class="smtp-group-content">
        <?php if ($groupConfigs): ?>
          <?php foreach ($groupConfigs as $c): ?>
          <div class="smtp-email-row">
            <div class="smtp-email-identity"><strong><?=e($c['from_email'])?></strong><span><?=e($c['from_name'] ?: $c['user'] ?: '未设置发件人名称')?></span></div>
            <div class="smtp-email-meta"><span><?=e($c['host'])?>:<?=intval($c['port'])?></span><small><?=$c['use_ssl']?'SSL':($c['use_starttls']?'STARTTLS':'无加密')?></small></div>
            <div class="smtp-actions">
              <a href="user_smtp.php?edit=<?=urlencode($c['id'])?>" class="btn btn-sm btn-gray">编辑</a>
              
              <form method="post" onsubmit="return confirm('确定删除这个邮箱？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn btn-sm btn-red">删除</button></form>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?><p class="smtp-group-empty">这个分组还没有邮箱，请编辑邮箱并选择该分组。</p><?php endif; ?>
        <div class="smtp-group-footer"><a href="user_smtp.php?new=1&group_id=<?=intval($g['id'])?>" class="btn btn-sm">+ 添加邮箱</a><form method="post" onsubmit="return confirm('删除后，组内邮箱会变为未分组，确定删除？')"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="<?=intval($g['id'])?>"><button class="btn btn-sm btn-red">删除分组</button></form></div>
      </div>
    </details>
  <?php endforeach; ?>
  <?php $ungroupedConfigs = $configsByGroup[0] ?? []; if ($ungroupedConfigs): ?>
    <details class="smtp-group-block">
      <summary><span class="smtp-group-chevron"></span><span class="smtp-group-summary"><strong>未分组</strong><small><?=count($ungroupedConfigs)?> 个邮箱</small></span><span class="smtp-group-open-text">查看邮箱</span></summary>
      <div class="smtp-group-content"><?php foreach ($ungroupedConfigs as $c): ?>
        <div class="smtp-email-row"><div class="smtp-email-identity"><strong><?=e($c['from_email'])?></strong><span><?=e($c['from_name'] ?: $c['user'] ?: '未设置发件人名称')?></span></div><div class="smtp-email-meta"><span><?=e($c['host'])?>:<?=intval($c['port'])?></span><small><?=$c['use_ssl']?'SSL':($c['use_starttls']?'STARTTLS':'无加密')?></small></div><div class="smtp-actions"><a href="user_smtp.php?edit=<?=urlencode($c['id'])?>" class="btn btn-sm btn-gray">编辑</a><form method="post" onsubmit="return confirm('确定删除这个邮箱？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn btn-sm btn-red">删除</button></form></div></div>
      <?php endforeach; ?><div class="smtp-group-footer"><span>编辑邮箱可将其移入已有分组</span></div></div>
    </details>
  <?php endif; ?>
  <?php if (!$groups && !$ungroupedConfigs): ?><p class="empty">还没有邮箱，点击上方“添加邮箱”即可直接添加，分组不是必需的。</p><?php endif; ?>
  </div>
</div>

<div class="modal" id="groupModal" onclick="if(event.target===this)closeGroupModal()">
  <div class="modal-box smtp-group-modal" role="dialog" aria-modal="true" aria-labelledby="groupModalTitle">
    <h2 id="groupModalTitle">创建邮箱分组</h2>
    <p>创建后，可在编辑邮箱时将一个或多个邮箱加入该组。</p>
    <form method="post">
      <input type="hidden" name="action" value="add_group">
      <div class="form-group"><label for="groupNameInput">分组名称</label><input id="groupNameInput" name="group_name" maxlength="100" placeholder="例如：默认发信组" autocomplete="off" required></div>
      <div class="smtp-group-modal-actions"><button type="button" class="btn btn-gray" onclick="closeGroupModal()">取消</button><button class="btn" type="submit">保存分组</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
var smtpProviders = {
  'qq.com':['smtp.qq.com','465','ssl','QQ 邮箱'], 'foxmail.com':['smtp.qq.com','465','ssl','Foxmail'],
  '163.com':['smtp.163.com','465','ssl','163 邮箱'], '126.com':['smtp.126.com','465','ssl','126 邮箱'],
  'yeah.net':['smtp.yeah.net','465','ssl','Yeah 邮箱'], 'sina.com':['smtp.sina.com','465','ssl','新浪邮箱'],
  'sina.cn':['smtp.sina.com','465','ssl','新浪邮箱'], 'aliyun.com':['smtp.aliyun.com','465','ssl','阿里邮箱'],
  'gmail.com':['smtp.gmail.com','465','ssl','Gmail'], 'outlook.com':['smtp-mail.outlook.com','587','starttls','Outlook'],
  'hotmail.com':['smtp-mail.outlook.com','587','starttls','Hotmail'], 'live.com':['smtp-mail.outlook.com','587','starttls','Microsoft 邮箱']
};
var editingSmtp = <?=json_encode((bool)$c)?>;
function applySmtpPreset(force) {
  var email = document.getElementById('smtpEmail');
  if (!email) return;
  var domain = String(email.value || '').toLowerCase().split('@').pop();
  var preset = smtpProviders[domain];
  var hint = document.getElementById('smtpPresetHint');
  if (preset) {
    if (force || !editingSmtp || !document.getElementById('smtpHost').value) document.getElementById('smtpHost').value = preset[0];
    if (force || !editingSmtp || !document.getElementById('smtpPort').value) document.getElementById('smtpPort').value = preset[1];
    if (force || !editingSmtp) document.getElementById('smtpSecurity').value = preset[2];
    if (!document.getElementById('smtpUser').value) document.getElementById('smtpUser').value = email.value;
    hint.textContent = '已自动识别：' + preset[3]; hint.style.color = '#16824b';
  } else if (email.value.indexOf('@') > 0) {
    hint.textContent = '特殊邮箱，请展开高级设置填写 SMTP 服务器'; hint.style.color = '#b66b08';
  } else hint.textContent = '';
}
function updateSmtpPort() {
  var security = document.getElementById('smtpSecurity');
  var port = document.getElementById('smtpPort');
  if (!security || !port) return;
  port.value = security.value === 'ssl' ? '465' : (security.value === 'starttls' ? '587' : '25');
}
applySmtpPreset(false);
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
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') closeGroupModal();
});
</script>
