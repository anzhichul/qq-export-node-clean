<?php
$message = flash_get('message');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
  $old = strval($_POST['old_pass'] ?? '');
  $new = strval($_POST['new_pass'] ?? '');
  try {
    if (strlen($new) < 6) throw new Exception('新密码至少 6 位');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([intval($_SESSION['user_id'] ?? 0)]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($old, $hash)) throw new Exception('原密码不正确');
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
      ->execute([password_hash($new, PASSWORD_DEFAULT), intval($_SESSION['user_id'])]);
    $message = '密码修改成功，下次登录请使用新密码';
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=password'));
  exit;
}
?>
<h2 class="page-title">修改密码</h2>
<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>
<div class="card" style="max-width:400px">
<form method="post">
  <input type="hidden" name="action" value="change_password">
  <div class="form-group"><label>原密码</label><input type="password" name="old_pass" required></div>
  <div class="form-group"><label>新密码</label><input type="password" name="new_pass" required minlength="6"></div>
  <button type="submit" class="btn">修改</button>
</form>
</div>
