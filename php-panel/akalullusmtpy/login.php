<?php
if (!empty($_SESSION['user_id']) && is_admin()) {
  header('Location: /akalullusmtpy/?page=dashboard');
  exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  try {
    if (!$username || !$password) throw new Exception('请输入用户名和密码');
    if (geetest_enabled()) {
      $gtOk = geetest_validate(
        strval($_POST['geetest_lot_number'] ?? ''),
        strval($_POST['geetest_captcha_output'] ?? ''),
        strval($_POST['geetest_pass_token'] ?? ''),
        strval($_POST['geetest_gen_time'] ?? '')
      );
      if (!$gtOk) throw new Exception('请先完成安全验证');
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username=? AND status=?');
    $stmt->execute([$username, 'active']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('用户名或密码错误');
    if ($row['role'] !== 'admin') throw new Exception('该账号无后台权限，请使用管理员账号登录');
    if (!password_verify($password, $row['password_hash'])) throw new Exception('用户名或密码错误');
    session_regenerate_id(true);
    db()->prepare('UPDATE users SET last_login_at=? WHERE id=?')->execute([now(), $row['id']]);
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['display_name'] = $row['display_name'] ?: $row['username'];
    $_SESSION['role'] = $row['role'];
    header('Location: /akalullusmtpy/?page=dashboard');
    exit;
  } catch (Exception $e) { $error = $e->getMessage(); }
}
$gtEnabled = geetest_enabled();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YUN信 - 管理后台登录</title>
<link rel="icon" type="image/svg+xml" href="../images/yun-mail.svg">
<link rel="stylesheet" href="../css/style.css?v=27">
<?php if ($gtEnabled): ?><script src="https://static.geetest.com/v4/gt4.js"></script><?php endif; ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <img class="login-brand-icon" src="../images/yun-mail.svg" alt="YUN信">
    <h1>YUN信管理后台</h1>
    <p>管理员专用登录入口</p>
    <?php if ($error): ?><p style="color:#c73d3d;font-size:13px;margin-bottom:14px"><?=e($error)?></p><?php endif; ?>
    <form method="post" id="adminLoginForm" style="text-align:left">
      <div class="form-group">
        <label>管理员用户名</label>
        <input name="username" value="<?=e($_POST['username']??'')?>" required autocomplete="username">
      </div>
      <div class="form-group">
        <label>密码</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <?php if ($gtEnabled): ?>
      <input type="hidden" name="geetest_lot_number" id="gtLot">
      <input type="hidden" name="geetest_captcha_output" id="gtOutput">
      <input type="hidden" name="geetest_pass_token" id="gtToken">
      <input type="hidden" name="geetest_gen_time" id="gtGen">
      <?php endif; ?>
      <button type="submit" class="btn" id="loginBtn" style="width:100%;padding:11px">进入后台</button>
    </form>
    <p style="margin-top:16px;font-size:12px"><a href="/?page=login" style="color:#6d7c8d">普通用户入口</a></p>
  </div>
</div>
<?php if ($gtEnabled): ?>
<script>
var gtCaptcha = null, gtReady = false, gtPending = false, gtDeadline = false;
setTimeout(function () { gtDeadline = true; if (gtPending) document.getElementById('adminLoginForm').submit(); }, 12000);
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
      var v = captcha.getValidate();
      if (v) {
        document.getElementById('gtLot').value = v.lot_number || '';
        document.getElementById('gtOutput').value = v.captcha_output || '';
        document.getElementById('gtToken').value = v.pass_token || '';
        document.getElementById('gtGen').value = v.gen_time || '';
        document.getElementById('adminLoginForm').submit();
      }
    });
    captcha.onClose(function () { gtPending = false; });
  });
} catch (e) { gtDeadline = true; }
document.getElementById('adminLoginForm').addEventListener('submit', function (e) {
  if (document.getElementById('gtLot').value) return;
  e.preventDefault();
  if (gtCaptcha && gtReady) { gtCaptcha.showCaptcha(); return; }
  if (gtDeadline) { document.getElementById('adminLoginForm').submit(); return; }
  gtPending = true;
});
</script>
<?php endif; ?>
</body>
</html>
