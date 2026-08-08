<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

$newKey = '';
$error = '';
try {
  db()->exec("CREATE TABLE IF NOT EXISTS device_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    device_name VARCHAR(100) NOT NULL DEFAULT '',
    device_key_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_seen INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    KEY idx_device_user (user_id),
    UNIQUE KEY uniq_device_hash (device_key_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flashKey = '';
    $error = '';
    try {
      if (($_POST['action'] ?? '') === 'generate') {
        $name = mb_substr(trim($_POST['device_name'] ?? ''), 0, 100);
        $key = 'dk-' . bin2hex(random_bytes(32));
        $stmt = db()->prepare('INSERT INTO device_keys(user_id,username,device_name,device_key_hash,status,last_seen,created_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([intval($_SESSION['user_id']), $_SESSION['username'], $name, hash('sha256', $key), 'active', 0, now()]);
        $flashKey = $key;
        log_operation('generate_device_key', 'device_key', strval(db()->lastInsertId()), '生成设备密钥');
      }
      if (($_POST['action'] ?? '') === 'revoke') {
        $id = intval($_POST['id'] ?? 0);
        db()->prepare('UPDATE device_keys SET status=? WHERE id=? AND user_id=?')->execute(['disabled', $id, intval($_SESSION['user_id'])]);
        log_operation('revoke_device_key', 'device_key', strval($id), '吊销设备密钥');
      }
    } catch (Exception $e) {
      $error = $e->getMessage();
    }
    $_SESSION['flash_dev_key'] = $flashKey;
    flash_set('error', $error);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=user_devices'));
    exit;
  }
  $newKey = $_SESSION['flash_dev_key'] ?? '';
  unset($_SESSION['flash_dev_key']);
  $error = flash_get('error');

  $stmt = db()->prepare('SELECT * FROM device_keys WHERE user_id=? ORDER BY created_at DESC');
  $stmt->execute([intval($_SESSION['user_id'])]);
  $devices = $stmt->fetchAll();
} catch (Exception $e) {
  $error = $e->getMessage();
}
?>
<h2 class="page-title">我的设备</h2>

<?php if ($newKey): ?>
<div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5">
  <h3 style="color:#1d7a42">设备密钥已生成（仅显示一次，请立即保存）</h3>
  <div style="font-family:monospace;background:#fff;border:1px solid #d8dee6;border-radius:8px;padding:12px;margin:10px 0;word-break:break-all;font-size:13px" id="newKeyBox"><?=e($newKey)?></div>
  <button type="button" class="btn btn-sm" onclick="copyKey()">复制密钥</button>
  <span style="color:#6d7c8d;font-size:12px;margin-left:8px">密钥只存储在服务器哈希，丢失后需重新生成</span>
</div>
<script>function copyKey(){var t=document.getElementById('newKeyBox').innerText;navigator.clipboard.writeText(t).then(function(){toast('已复制')})}</script>
<?php endif; ?>

<?php if ($error): ?><p style="color:#c73d3d"><?=e($error)?></p><?php endif; ?>

<div class="card">
  <div style="display:flex;gap:6px;margin-bottom:14px">
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="action" value="generate">
      <input name="device_name" placeholder="设备名称（可选，例如：我的手机）" style="flex:1;min-width:200px">
      <button type="submit" class="btn btn-sm">+ 生成设备密钥</button>
    </form>
  </div>
  <?php if ($devices): ?>
  <div class="table-wrap">
  <table><thead><tr><th>ID</th><th>设备名称</th><th>状态</th><th>最近心跳</th><th>创建时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($devices as $d): ?>
    <tr>
      <td><?=intval($d['id'])?></td>
      <td><?=e($d['device_name']?:'-')?></td>
      <td>
        <?php if ($d['status'] === 'active'): ?>
          <span style="color:#1d7a42">启用中</span>
          <?=intval($d['last_seen']) >= now() - 300 ? ' <b style="color:#1d7a42">●在线</b>' : ''?>
        <?php else: ?><span style="color:#c73d3d">已吊销</span><?php endif; ?>
      </td>
      <td><?=$d['last_seen'] ? date('Y-m-d H:i:s', intval($d['last_seen'])) : '从未'?></td>
      <td><?=date('Y-m-d H:i:s', intval($d['created_at']))?></td>
      <td>
        <?php if ($d['status'] === 'active'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('吊销后该设备将无法发信，确定？')">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?=intval($d['id'])?>">
          <button class="btn btn-sm btn-red">吊销</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  </div>
  <?php else: ?><p class="empty">还没有设备，点击上方「生成设备密钥」，把密钥填到手机 App 即可绑定</p><?php endif; ?>
</div>

<div class="card" style="color:#6d7c8d;font-size:13px">
  <b>使用说明</b><br>
  1. 在「我的发信」创建发信任务，每任务扣 1 点数；<br>
  2. 手机上安装 App，打开后输入本页生成的设备密钥（App 只走 PHP 接口，不使用 Node）；<br>
  3. App 自动识别密钥。设备密钥会加密保存并显示你的任务列表，任务密钥只用于本次单个任务；<br>
  4. 选择任务和发送速度后，点击“开始发送”，结果会逐封实时回传。
</div>
