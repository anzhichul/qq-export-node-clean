<?php
if (!empty($_SESSION['user_id'])) {
  header('Location: /?page=' . (($_SESSION['role'] ?? '') === 'admin' ? 'dashboard' : 'user_home'));
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $displayName = trim($_POST['display_name'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';
  $error = '';
  try {
    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $username)) throw new Exception('用户名需为 4-30 位字母、数字或下划线');
    if (mb_strlen($displayName) < 2) throw new Exception('显示名称至少 2 个字');
    if (strlen($password) < 6) throw new Exception('密码至少 6 位');
    if ($password !== $confirm) throw new Exception('两次输入的密码不一致');
    if (geetest_enabled()) {
      $gtOk = geetest_validate(
        strval($_POST['geetest_lot_number'] ?? ''),
        strval($_POST['geetest_captcha_output'] ?? ''),
        strval($_POST['geetest_pass_token'] ?? ''),
        strval($_POST['geetest_gen_time'] ?? '')
      );
      if (!$gtOk) throw new Exception('请先完成安全验证');
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE username=?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) throw new Exception('用户名已存在');
    db()->prepare('INSERT INTO users(username,password_hash,display_name,balance_points,status,role,last_login_at,created_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, 0, 'active', 'user', 0, now()]);
    echo '<script>alert("注册成功，请登录");location.href="?page=login";</script>'; exit;
  } catch (Exception $e) { $error = $e->getMessage(); }
}
$gtEnabled = geetest_enabled();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YUN信 - 注册</title>
<link rel="icon" type="image/svg+xml" href="images/yun-mail.svg">
<link rel="stylesheet" href="css/style.css?v=30">
<?php if ($gtEnabled): ?><script src="https://static.geetest.com/v4/gt4.js"></script><?php endif; ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <img class="login-brand-icon" src="images/yun-mail.svg" alt="YUN信">
    <h1>YUN信</h1>
    <p>创建普通用户账号</p>
    <?php if (isset($error)): ?><p style="color:#c73d3d;font-size:13px;margin-bottom:14px"><?=e($error)?></p><?php endif; ?>
    <form method="post" id="registerForm" style="text-align:left">
      <div class="form-group">
        <label>用户名</label>
        <input name="username" value="<?=e($_POST['username']??'')?>" placeholder="4-30 位字母、数字或下划线" required>
      </div>
      <div class="form-group">
        <label>显示名称</label>
        <input name="display_name" value="<?=e($_POST['display_name']??'')?>" placeholder="你的昵称" required>
      </div>
      <div class="form-group">
        <label>密码</label>
        <input type="password" name="password" placeholder="至少 6 位" required>
      </div>
      <div class="form-group">
        <label>确认密码</label>
        <input type="password" name="confirm_password" placeholder="再次输入密码" required>
      </div>
      <?php if ($gtEnabled): ?>
      <input type="hidden" name="geetest_lot_number" id="gtLot">
      <input type="hidden" name="geetest_captcha_output" id="gtOutput">
      <input type="hidden" name="geetest_pass_token" id="gtToken">
      <input type="hidden" name="geetest_gen_time" id="gtGen">
      <?php endif; ?>
      <button type="submit" class="btn" id="registerBtn" style="width:100%;padding:11px">注 册</button>
    </form>
    <p style="margin-top:16px;font-size:12px"><a href="?page=login">已有账号？去登录</a></p>
  </div>
</div>
<?php if ($gtEnabled): ?>
<script>
var gtCaptcha = null, gtReady = false, gtPending = false;
try {
  initGeetest4({
    captchaId: '<?=GT4_CAPTCHA_ID?>',
    product: 'bind',
    language: 'zho',
    protocol: 'https://'
  }, function (captcha) {
    gtCaptcha = captcha;
    captcha.onReady(function () { gtReady = true; if (gtPending) captcha.showCaptcha(); });
    captcha.onSuccess(function () {
      var value = captcha.getValidate();
      if (!value) return;
      document.getElementById('gtLot').value = value.lot_number || '';
      document.getElementById('gtOutput').value = value.captcha_output || '';
      document.getElementById('gtToken').value = value.pass_token || '';
      document.getElementById('gtGen').value = value.gen_time || '';
      document.getElementById('registerForm').submit();
    });
    captcha.onClose(function () { gtPending = false; });
    captcha.onError(function () { gtPending = false; });
  });
} catch (error) {}
document.getElementById('registerForm').addEventListener('submit', function (event) {
  if (document.getElementById('gtLot').value) return;
  event.preventDefault();
  gtPending = true;
  if (gtCaptcha && gtReady) gtCaptcha.showCaptcha();
});
</script>
<?php endif; ?>
</body>
</html>
