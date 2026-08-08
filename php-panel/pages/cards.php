<?php
if (!is_admin()) { echo '<div class="empty">仅管理员可访问</div>'; return; }

$newCodes = [];
$message = '';
$error = '';

try {
  db()->exec("CREATE TABLE IF NOT EXISTS cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    card_type VARCHAR(20) NOT NULL DEFAULT 'days',
    days INT NOT NULL DEFAULT 0,
    points INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'unused',
    used_by INT NOT NULL DEFAULT 0,
    used_username VARCHAR(50) NOT NULL DEFAULT '',
    used_at INT NOT NULL DEFAULT 0,
    created_by VARCHAR(50) NOT NULL DEFAULT '',
    created_at INT NOT NULL,
    expires_at INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_card_code (code),
    KEY idx_cards_status (status, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db = db();
  try { $db->exec("ALTER TABLE cards ADD COLUMN grant_points INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
  try { $db->exec("ALTER TABLE cards ADD COLUMN combo_days INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
  try { $db->exec("CREATE TABLE IF NOT EXISTS points_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    `change` INT NOT NULL,
    balance_after INT NOT NULL,
    reason VARCHAR(50) NOT NULL DEFAULT '',
    ref_code VARCHAR(40) NOT NULL DEFAULT '',
    created_at INT NOT NULL,
    KEY idx_points_ledger_user (user_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {    if ($action === 'generate') {
      $type = $_POST['type'] ?? 'days';
      $value = max(0, intval($_POST['value'] ?? 0));
      $count = max(1, min(200, intval($_POST['count'] ?? 1)));
      $validDays = max(0, intval($_POST['valid_days'] ?? 0));
      $grantPoints = max(0, intval($_POST['grant_points'] ?? 0));
      if (!in_array($type, ['days','points','combo'], true)) throw new Exception('卡密类型错误');
      if ($type === 'days' && $value <= 0) throw new Exception('会员天数必须大于 0');
      if ($type === 'points' && $value <= 0) throw new Exception('点数值必须大于 0');
      if ($type === 'combo' && $value <= 0 && $grantPoints <= 0) throw new Exception('时长或点数至少填写一个');
      $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      $insert = $db->prepare('INSERT INTO cards(code,card_type,days,points,grant_points,combo_days,status,used_by,used_username,used_at,created_by,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $genCount = 0;
      while ($genCount < $count) {
        $s = '';
        for ($i = 0; $i < 16; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        $code = 'LK-' . implode('-', str_split($s, 4));
        try {
          $insert->execute([
            $code, $type,
            $type === 'points' ? 0 : $value,
            $type === 'days' ? 0 : $value,
            $grantPoints,
            $type === 'combo' ? $value : 0,
            'unused', 0, '', 0, $_SESSION['username'] ?? 'admin', now(),
            $validDays > 0 ? now() + $validDays * 86400 : 0,
          ]);
          $newCodes[] = $code;
          $genCount++;
        } catch (Exception $e) {
          if (strpos($e->getMessage(), 'Duplicate') === false) throw $e;
        }
      }
      $detail = $type . ':' . $value;
      if ($grantPoints > 0) $detail .= '+' . $grantPoints . '点';
      $detail .= ' x' . count($newCodes);
      $message = '已生成 ' . count($newCodes) . ' 张卡密';
      log_operation('generate_cards', 'card', '', $detail);
    }
    if ($action === 'toggle') {
      $id = intval($_POST['id'] ?? 0);
      db()->prepare("UPDATE cards SET status=IF(status='unused','disabled',IF(status='disabled','unused',status)) WHERE id=?")->execute([$id]);
      $message = '状态已更新';
    }
    if ($action === 'delete') {
      $id = intval($_POST['id'] ?? 0);
      db()->prepare('DELETE FROM cards WHERE id=?')->execute([$id]);
      $message = '已删除';
    }
    } catch (Exception $e) {
      $error = $e->getMessage();
    }
    $_SESSION['flash_codes'] = $newCodes;
    flash_set('message', $message);
    flash_set('error', $error);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '?page=cards'));
    exit;
  }
  $newCodes = $_SESSION['flash_codes'] ?? [];
  unset($_SESSION['flash_codes']);
  $message = flash_get('message');
  $error = flash_get('error');

  $filter = $_GET['status'] ?? '';
  $search = trim($_GET['q'] ?? '');
  $perPage = 20;
  $pageNum = max(1, intval($_GET['p'] ?? 1));
  $where = '1';
  $params = [];
  if ($filter === 'used') { $where .= " AND status='used'"; }
  elseif ($filter === 'unused') { $where .= " AND status='unused'"; }
  elseif ($filter === 'disabled') { $where .= " AND status='disabled'"; }
  if ($search !== '') { $where .= ' AND code LIKE ?'; $params[] = '%' . $search . '%'; }
  $countStmt = db()->prepare("SELECT COUNT(*) FROM cards WHERE $where");
  $countStmt->execute($params);
  $total = intval($countStmt->fetchColumn());
  $totalPages = max(1, intval(ceil($total / $perPage)));
  if ($pageNum > $totalPages) $pageNum = $totalPages;
  $offset = ($pageNum - 1) * $perPage;
  $sql = "SELECT * FROM cards WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . intval($perPage) . ' OFFSET ' . intval($offset);
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $cards = $stmt->fetchAll();
} catch (Exception $e) {
  $error = $e->getMessage();
}
?>
<h2 class="page-title">卡密管理</h2>

<?php if ($message): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<?php if ($newCodes): ?>
<div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5">
  <h3 style="color:#1d7a42">新生成卡密（请复制保存）</h3>
  <textarea id="codesBox" rows="8" readonly style="width:100%;font-family:monospace;font-size:12px;background:#fff;border:1px solid #d8dee6;border-radius:8px;padding:10px"><?=e(implode("\n", $newCodes))?></textarea>
  <button type="button" class="btn btn-sm" onclick="copyCodes()">复制全部</button>
</div>
<script>function copyCodes(){var t=document.getElementById('codesBox');t.select();document.execCommand('copy');toast('已复制')}</script>
<?php endif; ?>

<div class="card">
  <div class="list-toolbar">
    <div class="filter-tabs" aria-label="卡密状态筛选">
      <a href="?page=cards" class="filter-tab <?=$filter===''?'active':''?>">全部</a>
      <a href="?page=cards&status=unused" class="filter-tab <?=$filter==='unused'?'active':''?>">未使用</a>
      <a href="?page=cards&status=used" class="filter-tab <?=$filter==='used'?'active':''?>">已使用</a>
      <a href="?page=cards&status=disabled" class="filter-tab <?=$filter==='disabled'?'active':''?>">已禁用</a>
    </div>
    <button type="button" class="btn" onclick="openCardModal()" style="width:auto;white-space:nowrap">＋ 生成卡密</button>
    <form method="get" class="toolbar-search">
      <input type="hidden" name="page" value="cards">
      <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?=e($filter)?>"><?php endif; ?>
      <span class="search-field"><span class="search-icon" aria-hidden="true"></span><input name="q" value="<?=e($search)?>" placeholder="输入卡密搜索" aria-label="搜索卡密"></span>
      <?php if ($search !== ''): ?><a class="search-clear" href="?page=cards<?=$filter!==''?'&status='.urlencode($filter):''?>">清除</a><?php endif; ?>
      <button class="btn toolbar-search-btn">搜索</button>
    </form>
  </div>
  <div class="table-wrap" style="-webkit-overflow-scrolling:touch">
  <table style="width:920px;font-size:12px"><thead><tr><th>卡密</th><th>类型</th><th>面值</th><th>状态</th><th>使用者</th><th>生成时间</th><th>有效期至</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($cards as $c): ?>
    <tr>
      <td style="font-family:monospace"><?=e($c['code'])?></td>
      <td><?php $type=$c['card_type'];echo $type==='days'?'时长':($type==='points'?'点数':'组合');?></td>
      <td><?php $type=$c['card_type'];$face=intval($type==='points'?$c['points']:$c['days']);$unit=$type==='points'?' 点':' 天';echo $face.$unit;if(intval($c['grant_points'])>0)echo ' + '.intval($c['grant_points']).' 点';?></td>
      <td>
        <?php if ($c['status']==='used'): ?><span style="color:#1d7a42">已使用</span>
        <?php elseif ($c['status']==='disabled'): ?><span style="color:#c73d3d">已禁用</span>
        <?php else: ?><span style="color:#1769e0">未使用</span><?php endif; ?>
      </td>
      <td><?=$c['status']==='used'?e($c['used_username']).'<br><small style="color:#6d7c8d">'.date('Y-m-d H:i',intval($c['used_at'])).'</small>':'-'?></td>
      <td><?=date('Y-m-d H:i', intval($c['created_at']))?></td>
      <td><?=intval($c['expires_at'])>0?date('Y-m-d', intval($c['expires_at'])):'永久'?></td>
      <td style="white-space:nowrap">
        <?php if ($c['status']==='unused' || $c['status']==='disabled'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=intval($c['id'])?>"><button class="btn btn-sm btn-gray"><?=$c['status']==='disabled'?'启用':'禁用'?></button></form>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=intval($c['id'])?>"><button class="btn btn-sm btn-red">删除</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$cards): ?><tr><td colspan="8"><p class="empty">暂无卡密</p></td></tr><?php endif; ?>
  </tbody></table>
  </div>

  <?php render_pager($pageNum, $totalPages, ['page' => 'cards', 'status' => $filter, 'q' => $search]); ?>
</div>

<div class="modal" id="cardModal">
  <div class="modal-box">
    <h2>生成卡密</h2>
    <form method="post" id="cardGenForm">
      <input type="hidden" name="action" value="generate">
      <div class="form-group"><label>卡密类型</label>
        <select name="type" id="cardTypeSel" onchange="onCardTypeChange()">
          <option value="days">时长卡（仅续费会员）</option>
          <option value="points">点数卡（仅增加点数）</option>
          <option value="combo">组合卡（会员+点数）</option>
        </select>
      </div>
      <div class="form-group"><label id="valueLabel">会员天数</label><input name="value" type="number" min="0" value="30"></div>
      <div class="form-group"><label>附带点数(可选)</label><input name="grant_points" type="number" min="0" value="0"></div>
      <div class="form-group"><label>生成数量</label><input name="count" type="number" min="1" max="200" value="10"></div>
      <div class="form-group"><label>有效期(天,0=永久)</label><input name="valid_days" type="number" min="0" value="0"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
        <button type="button" class="btn btn-gray" onclick="closeCardModal()">取消</button>
        <button type="submit" class="btn">生成</button>
      </div>
    </form>
  </div>
</div>

<script>
function onCardTypeChange() {
  var t = document.getElementById('cardTypeSel').value;
  document.getElementById('valueLabel').textContent = t === 'points' ? '点数值' : '会员天数';
}
function openCardModal() { document.getElementById('cardModal').classList.add('show'); }
function closeCardModal() { document.getElementById('cardModal').classList.remove('show'); }
document.getElementById('cardModal').addEventListener('click', function (e) { if (e.target === this) closeCardModal(); });
</script>
