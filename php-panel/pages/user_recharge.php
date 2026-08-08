<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redeem') {
  $message = '';
  $error = '';
  $code = strtoupper(trim($_POST['code'] ?? ''));
  try {
    if ($code === '') throw new Exception('请输入卡密');
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM cards WHERE code=? AND status='unused' FOR UPDATE");
    $stmt->execute([$code]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) { $pdo->rollBack(); throw new Exception('卡密无效、已使用或已禁用'); }
    if (intval($card['expires_at']) > 0 && intval($card['expires_at']) < now()) {
      $pdo->rollBack(); throw new Exception('卡密已超过有效期');
    }
    $upd = $pdo->prepare("UPDATE cards SET status='used',used_by=?,used_username=?,used_at=? WHERE code=? AND status='unused'");
    $upd->execute([intval($_SESSION['user_id']), $_SESSION['username'], now(), $code]);
    if ($upd->rowCount() === 0) { $pdo->rollBack(); throw new Exception('卡密已被使用'); }

    $userId = intval($_SESSION['user_id']);
    $addDays = 0;
    $addPoints = 0;
    $grantPoints = intval($card['grant_points'] ?? 0);
    if ($card['card_type'] === 'days') {
      $addDays = intval($card['days']);
      $addPoints = $grantPoints;
    } elseif ($card['card_type'] === 'points') {
      $addPoints = intval($card['points']);
    } else {
      $addDays = intval($card['days'] ?: $card['combo_days']);
      $addPoints = intval($card['points']) + $grantPoints;
    }
    if ($addDays > 0) {
      $exp = membership_expires_at();
      $base = max(now(), $exp);
      $newExp = $base + $addDays * 86400;
      $pdo->prepare('UPDATE users SET membership_expires_at=? WHERE id=?')->execute([$newExp, $userId]);
    }
    $newBalance = 0;
    if ($addPoints > 0) {
      $pdo->prepare('UPDATE users SET balance_points=balance_points+? WHERE id=?')->execute([$addPoints, $userId]);
      $newBalance = intval($pdo->query('SELECT balance_points FROM users WHERE id=' . intval($userId))->fetchColumn() ?: 0);
      $pdo->prepare('INSERT INTO points_ledger(user_id,`change`,balance_after,reason,ref_code,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$userId, $addPoints, $newBalance, 'redeem_card', $code, now()]);
    }
    $parts = [];
    if ($addDays > 0) $parts[] = '会员 +' . $addDays . ' 天';
    if ($addPoints > 0) $parts[] = '点数 +' . $addPoints;
    $message = '激活成功：' . implode('，', $parts);
    if ($addDays > 0) $message .= '，到期时间 ' . date('Y-m-d H:i', $newExp);
    $pdo->commit();
    log_operation('redeem_card', 'card', $code, $card['card_type'] . ':' . ($addDays > 0 ? $addDays . '天' : '') . ($addPoints > 0 ? ($addDays>0?'+':'') . $addPoints . '点' : ''));
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
  flash_set('message', $message);
  flash_set('error', $error);
  header('Location: ?page=user_recharge');
  exit;
}

$message = flash_get('message');
$error = flash_get('error');

$purchaseUrl = get_setting('purchase_url');
?>
<h2 class="page-title">充值中心</h2>

<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<div class="card">
  <h3>卡密激活 / 充值</h3>
  <p style="color:#6d7c8d;font-size:13px">输入卡密即可激活或续费。时长卡用于续费会员，点数卡用于增加发信点数，组合卡两者兼顾。</p>
  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
    <input type="hidden" name="action" value="redeem">
    <input name="code" placeholder="输入卡密，如 LK-XXXXX-XXXXX-XXXXX-XXXXX" style="flex:1;min-width:220px;text-transform:uppercase" required>
    <button type="submit" class="btn">激活</button>
  </form>
  <?php if ($purchaseUrl !== ''): ?>
  <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #dde3ea">
    <p style="color:#6d7c8d;font-size:13px;margin-bottom:8px">还没有卡密？</p>
    <a href="<?=e($purchaseUrl)?>" target="_blank" rel="noopener" class="btn btn-primary">购买激活码</a>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="color:#6d7c8d;font-size:13px">
  <b>使用说明</b><br>
  1. 卡密分三类：<b>时长卡</b>（仅增加会员天数）、<b>点数卡</b>（仅增加发信点数）和 <b>组合卡</b>（同时延长会员并赠送点数）；<br>
  2. 创建卡密时还可设置<b>附带点数</b>，激活会员时一起赠送；<br>
  3. 每创建一个发信任务消耗 1 点数；<br>
  4. 会员到期后无法继续使用，需用时长卡或组合卡重新激活；<br>
  5. 卡密请通过官方渠道获取。
</div>
