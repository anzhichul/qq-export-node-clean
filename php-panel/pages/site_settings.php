<?php
if (!is_admin()) { echo '<div class="empty">仅管理员可访问</div>'; return; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
  $message = '';
  $error = '';
  try {
    $url = trim($_POST['purchase_url'] ?? '');
    if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new Exception('购买链接必须以 http:// 或 https:// 开头');
    set_setting('purchase_url', $url);

    $dl = trim($_POST['app_download_url'] ?? '');
    if ($dl !== '' && !preg_match('#^https?://#i', $dl)) throw new Exception('下载链接必须以 http:// 或 https:// 开头');
    set_setting('app_version_code', preg_replace('/\D/', '', $_POST['app_version_code'] ?? ''));
    set_setting('app_version_name', trim($_POST['app_version_name'] ?? ''));
    set_setting('app_download_url', $dl);
    set_setting('app_update_msg', trim($_POST['app_update_msg'] ?? ''));
    set_setting('app_update_force', ($_POST['app_update_force'] ?? '') === '1' ? '1' : '0');
    $message = '保存成功';
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ?page=site_settings');
  exit;
}

$message = flash_get('message');
$error = flash_get('error');
$purchaseUrl = get_setting('purchase_url');
$appVersionCode = get_setting('app_version_code');
$appVersionName = get_setting('app_version_name');
$appDownloadUrl = get_setting('app_download_url');
$appUpdateMsg = get_setting('app_update_msg');
$appUpdateForce = get_setting('app_update_force') === '1';
?>
<h2 class="page-title">站点设置</h2>
<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>
<div class="card" style="max-width:520px">
  <form method="post">
    <input type="hidden" name="action" value="save_settings">
    <div class="form-group">
      <label>购买激活码跳转链接</label>
      <input name="purchase_url" value="<?=e($purchaseUrl)?>" placeholder="https://example.com/buy" style="width:100%">
      <small style="color:#6d7c8d;display:block;margin-top:6px">用户点击「购买激活码」按钮后跳转到此地址。留空则按钮隐藏。</small>
    </div>
    <h3 style="margin:18px 0 10px;border-top:1px dashed #e3e8ef;padding-top:14px">App 版本更新通知</h3>
    <div class="form-group">
      <label>最新版本号（数字，用于对比，如 5）</label>
      <input name="app_version_code" value="<?=e($appVersionCode)?>" type="number" min="0" style="width:100%">
    </div>
    <div class="form-group">
      <label>版本名称（显示用，如 2.1.0）</label>
      <input name="app_version_name" value="<?=e($appVersionName)?>" placeholder="2.1.0" style="width:100%">
    </div>
    <div class="form-group">
      <label>APK 下载链接</label>
      <input name="app_download_url" value="<?=e($appDownloadUrl)?>" placeholder="https://example.com/app-v2.1.0.apk" style="width:100%">
      <small style="color:#6d7c8d;display:block;margin-top:6px">App 检测到新版本时，「去更新」按钮跳转到此地址。留空则只提示不跳转。</small>
    </div>
    <div class="form-group">
      <label>更新说明</label>
      <textarea name="app_update_msg" rows="3" placeholder="本次更新内容..." style="width:100%"><?=e($appUpdateMsg)?></textarea>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="app_update_force" value="1" <?=$appUpdateForce?'checked':''?>> 强制更新（不能关闭弹窗）</label>
    </div>
    <button type="submit" class="btn">保存</button>
  </form>
</div>
