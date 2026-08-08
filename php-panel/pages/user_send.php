<?php
if (!is_user()) { echo '<div class="empty">仅普通用户可访问</div>'; return; }

try {
  $pointLedgerSql = "CREATE TABLE IF NOT EXISTS points_ledger (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, `change` INT NOT NULL, balance_after INT NOT NULL, reason VARCHAR(50) NOT NULL DEFAULT '', ref_code VARCHAR(40) NOT NULL DEFAULT '', created_at INT NOT NULL, KEY idx_points_ledger_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { db()->exec($pointLedgerSql); } catch (Exception $e) {}
  $smtpGroupsSql = "CREATE TABLE IF NOT EXISTS smtp_groups (id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NOT NULL DEFAULT 0, owner_username VARCHAR(50) NOT NULL DEFAULT '', owner_role VARCHAR(20) NOT NULL DEFAULT 'user', name VARCHAR(80) NOT NULL, created_at INT NOT NULL, KEY idx_smtp_groups_owner (owner_user_id, owner_role)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { db()->exec($smtpGroupsSql); } catch (Exception $e) {}
  $smtpConfigsSql = "CREATE TABLE IF NOT EXISTS smtp_configs (id INT AUTO_INCREMENT PRIMARY KEY, owner_user_id INT NOT NULL DEFAULT 0, owner_username VARCHAR(50) NOT NULL DEFAULT '', owner_role VARCHAR(20) NOT NULL DEFAULT 'user', group_id INT NOT NULL DEFAULT 0, name VARCHAR(80) NOT NULL DEFAULT '', host VARCHAR(120) NOT NULL DEFAULT '', port INT NOT NULL DEFAULT 465, use_ssl INT NOT NULL DEFAULT 1, use_starttls INT NOT NULL DEFAULT 0, username VARCHAR(120) NOT NULL DEFAULT '', password VARCHAR(200) NOT NULL DEFAULT '', from_email VARCHAR(120) NOT NULL DEFAULT '', created_at INT NOT NULL, KEY idx_smtp_configs_group (group_id), KEY idx_smtp_configs_owner (owner_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { db()->exec($smtpConfigsSql); } catch (Exception $e) {}
  $exportRecordsSql = "CREATE TABLE IF NOT EXISTS export_records (id INT AUTO_INCREMENT PRIMARY KEY, account_uin VARCHAR(20) NOT NULL DEFAULT '', export_type VARCHAR(20) NOT NULL DEFAULT '', group_id VARCHAR(20) NOT NULL DEFAULT '', line_count INT NOT NULL DEFAULT 0, file_path VARCHAR(200) NOT NULL DEFAULT '', owner_user_id INT NOT NULL DEFAULT 0, created_by VARCHAR(50) NOT NULL DEFAULT '', created_at INT NOT NULL, KEY idx_export_records_account (account_uin, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { db()->exec($exportRecordsSql); } catch (Exception $e) {}
  $cardsSql = "CREATE TABLE IF NOT EXISTS cards (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, card_type VARCHAR(20) NOT NULL DEFAULT 'days', days INT NOT NULL DEFAULT 0, points INT NOT NULL DEFAULT 0, grant_points INT NOT NULL DEFAULT 0, combo_days INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'unused', used_by INT NOT NULL DEFAULT 0, used_username VARCHAR(50) NOT NULL DEFAULT '', used_at INT NOT NULL DEFAULT 0, created_by VARCHAR(50) NOT NULL DEFAULT '', created_at INT NOT NULL, expires_at INT NOT NULL DEFAULT 0, UNIQUE KEY uniq_card_code (code), KEY idx_cards_status (status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { db()->exec($cardsSql); } catch (Exception $e) {}
  $pointStmt = db()->prepare('SELECT balance_points FROM users WHERE id=?');
  $pointStmt->execute([$_SESSION['user_id']]);
  $balance = intval($pointStmt->fetchColumn());
  $ledgerStmt = db()->prepare('SELECT id,created_at,`change`,balance_after,reason,ref_code FROM points_ledger WHERE user_id=? ORDER BY id DESC LIMIT 10');
  $ledgerStmt->execute([$_SESSION['user_id']]);
  $ledgerRows = $ledgerStmt->fetchAll();

  $groupStmt = db()->prepare('SELECT g.id,g.name,COUNT(c.id) config_count FROM smtp_groups g LEFT JOIN smtp_configs c ON c.group_id=g.id AND c.owner_user_id=g.owner_user_id WHERE g.owner_user_id=? AND g.owner_role=? GROUP BY g.id,g.name HAVING COUNT(c.id)>0 ORDER BY g.name,g.id');
  $groupStmt->execute([intval($_SESSION['user_id']), 'user']);
  $smtpGroups = $groupStmt->fetchAll();
  $exportStmt = db()->prepare('SELECT er.* FROM export_records er INNER JOIN user_accounts ua ON ua.account_uin=er.account_uin WHERE ua.user_id=? ORDER BY er.created_at DESC LIMIT 100');
  $exportStmt->execute([$_SESSION['user_id']]);
  $exports = array_values(array_filter($exportStmt->fetchAll(), function ($item) {
    return intval($item['line_count'] ?? 0) > 0;
  }));

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_mail_job') {
    $groupId = intval($_POST['group_id'] ?? 0);
    $exportId = intval($_POST['export_id'] ?? 0);
    $recipients = trim($_POST['recipients'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $html = trim($_POST['html'] ?? '');
    if ($balance <= 0) throw new Exception('点数不足，请先充值');
    if (!$groupId) throw new Exception('请选择邮箱分组');
    if (!$exportId && !$recipients) throw new Exception('请先选择导出记录或手动填写收件人');
    $res = node_api('POST', '/api/mail/jobs', [
      'group_id' => $groupId,
      'export_record_id' => $exportId,
      'recipients' => $recipients,
      'subject' => $subject ?: 'QQ号列表',
      'text_content' => $content,
      'html_content' => $html,
      'created_by' => $_SESSION['username'],
      'owner_user_id' => intval($_SESSION['user_id']),
      'owner_role' => 'user',
    ]);
    db()->prepare('UPDATE users SET balance_points=balance_points-1 WHERE id=? AND balance_points>0')->execute([$_SESSION['user_id']]);
    $after = intval(db()->query('SELECT balance_points FROM users WHERE id=' . intval($_SESSION['user_id']))->fetchColumn() ?: 0);
    db()->prepare('INSERT INTO points_ledger(user_id,`change`,balance_after,reason,ref_code,created_at) VALUES(?,?,?,?,?,?)')
      ->execute([intval($_SESSION['user_id']), -1, $after, 'mail_job', strval($res['job_id'] ?? ''), now()]);
    $taskKey = strval($res['task_key'] ?? '');
    log_operation('user_create_mail_job', 'mail_job', strval($res['job_id'] ?? ''), '普通用户创建发信任务，扣除 1 点数，任务密钥 ' . $taskKey);
    echo '<script>alert("发信任务已创建，请记下任务密钥：' . $taskKey . '\\n在 App 中输入该密钥开始执行。");location.href="user_send.php?new_key=' . urlencode($taskKey) . '";</script>'; exit;
  }

  $newKey = trim($_GET['new_key'] ?? '');
} catch (Exception $e) { echo '<div class="card"><p style="color:#c73d3d">发信任务读取失败: ' . e($e->getMessage()) . '</p></div>'; return; }
?>

<h2 class="page-title">我的发信</h2>
<div class="stats"><div class="stat"><b><?=$balance?></b><small>当前点数</small></div></div>

<?php if ($newKey !== ''): ?>
<div class="card" style="border-color:#1769e0">
  <h3>任务已创建，任务密钥</h3>
  <p style="color:#6d7c8d;font-size:13px;margin-bottom:10px">在 App 中输入下面的任务密钥开始执行；任务 30 分钟内未执行会自动取消，执行完成后密钥自动失效。</p>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <code id="taskKeyBox" style="font-size:20px;font-weight:700;color:#0f3b77;background:#edf5ff;padding:10px 16px;border-radius:10px;letter-spacing:1px"><?=e($newKey)?></code>
    <button type="button" class="btn" onclick="navigator.clipboard.writeText('<?=e($newKey)?>');toast('已复制任务密钥')">复制密钥</button>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>创建发信任务</h3>
  <p style="color:#6d7c8d;font-size:13px;margin-bottom:12px">选择邮箱分组和收件人列表。软件每次领取发送批次时，会从所选组内随机使用一个邮箱发送。</p>
  <form method="post">
    <input type="hidden" name="action" value="send_mail_job">
    <div class="form-group"><label>邮箱分组</label>
      <select name="group_id" required>
        <option value="">-- 选择 --</option>
        <?php foreach ($smtpGroups as $g): ?>
        <option value="<?=intval($g['id'])?>"><?=e($g['name'])?>（<?=intval($g['config_count'])?> 个邮箱，随机发送）</option>
        <?php endforeach; ?>
      </select>
      <?php if (!$smtpGroups): ?><small style="color:#c73d3d">暂无可用邮箱分组，请先到“我的 SMTP”创建分组并将邮箱加入分组。</small><?php endif; ?>
    </div>
    <div class="form-group"><label>导出列表（收件人）</label>
      <select name="export_id" id="exportSelect" onchange="loadExportContent(this.value)">
        <option value="">-- 不使用导出记录，手动填写收件人 --</option>
        <?php foreach ($exports as $r): ?>
        <option value="<?=intval($r['id'])?>">[<?=$r['export_type']==='friends'?'好友':'群成员'?>] <?=e($r['account_uin'])?> - <?=intval($r['line_count'])?>条</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>收件人邮箱</label>
      <textarea name="recipients" id="sendRecipients" rows="6" placeholder="这里保存最终收件人列表，每行一个邮箱"></textarea>
    </div>
    <div class="form-group">
      <label>收件人列表编辑</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
        <input id="manualRecipient" placeholder="手动添加邮箱，例如 123456@qq.com" style="flex:1;min-width:220px">
        <button type="button" class="btn btn-gray" onclick="addManualRecipient()">添加邮箱</button>
        <button type="button" class="btn btn-gray" onclick="clearRecipients()">清空</button>
      </div>
      <div id="recipientList" class="recipient-list-box"></div>
      <div id="recipientCount" style="margin-top:8px;color:#6d7c8d;font-size:12px">当前收件人：0</div>
    </div>
    <div class="form-group"><label>邮件主题</label><input name="subject" id="sendSubject" placeholder="留空自动生成"></div>
    <textarea name="content" id="sendContent" rows="10" style="display:none"></textarea>
    <div class="form-group"><label>邮件正文（支持 HTML）</label><textarea name="html" id="sendHtml" rows="8" placeholder="输入邮件正文，可包含链接、图片和富文本"></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:6px"><button type="button" class="btn btn-sm btn-gray" onclick="previewMail()">👁 预览效果</button></div>
    </div>
    <div style="color:#6d7c8d;font-size:12px;margin-bottom:12px">当前规则：每创建 1 个发信任务扣 1 点数；任务密钥 30 分钟内有效，未执行自动取消。</div>
    <button type="submit" class="btn">创建发信任务</button>
  </form>
</div>

<div class="modal" id="previewModal" onclick="if(event.target===this)closePreview()">
  <div class="modal-box" style="width:min(720px,calc(100% - 30px))">
    <h2>邮件预览</h2>
    <p id="previewSubject" style="color:#6d7c8d;font-size:13px;margin-bottom:10px"></p>
    <iframe id="previewFrame" sandbox="" style="width:100%;height:420px;border:1px solid #d8dee6;border-radius:10px;background:#fff"></iframe>
    <div style="display:flex;justify-content:flex-end;margin-top:12px"><button type="button" class="btn btn-gray" onclick="closePreview()">关闭</button></div>
  </div>
</div>

<div class="card">
  <h3>点数流水</h3>
  <?php if (!$ledgerRows): ?>
    <p class="empty" style="margin-bottom:0">暂无点数变动记录</p>
  <?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>时间</th><th>变动</th><th>剩余</th><th>说明</th><th>关联卡密</th></tr></thead><tbody>
  <?php foreach ($ledgerRows as $row): ?>
    <tr>
      <td><?=date('Y-m-d H:i', intval($row['created_at']))?></td>
      <td style="color:<?=intval($row['change'])>0?'#1d7a42':'#c73d3d'?>"><?=intval($row['change'])>0?'+':''?><?=intval($row['change'])?></td>
      <td><?=intval($row['balance_after'])?></td>
      <td><?=e(($row['reason'] === 'redeem_card' ? '卡密激活' : ($row['reason'] === 'mail_job' ? '发信任务' : $row['reason'])))?></td>
      <td style="font-family:monospace"><?=e($row['ref_code'] ?? '')?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="card mail-task-card">
  <div class="mail-task-heading"><h3>任务列表</h3><span id="refreshHint"></span></div>
  <div class="table-wrap mail-task-wrap">
  <table id="taskTable" class="mail-task-table"><thead><tr><th>主题</th><th>任务密钥</th><th>状态</th><th>发送进度</th><th>创建时间</th><th>有效期</th></tr></thead>
  <tbody><tr><td colspan="6" class="mail-task-empty">加载中...</td></tr></tbody></table>
  </div>
</div>

<script>
function parseRecipientsText(text) {
  return String(text || '').split(/\r?\n|,|;/).map(function(item){ return item.trim(); }).filter(Boolean);
}

function renderRecipients(emails) {
  var list = document.getElementById('recipientList');
  list.innerHTML = '';
  if (!emails.length) {
    list.innerHTML = '<span style="font-size:12px;color:#9aa7b6">暂无收件人</span>';
    document.getElementById('recipientCount').textContent = '当前收件人：0';
    return;
  }
  document.getElementById('recipientCount').textContent = '当前收件人：' + emails.length;
  emails.forEach(function(email, index){
    var item = document.createElement('div');
    item.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#edf5ff;color:#0f3b77;border:1px solid #d8e5fb;border-radius:999px;padding:6px 10px;font-size:12px';
    var text = document.createElement('span');
    text.textContent = email;
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.style.cssText = 'border:0;background:transparent;color:#1769e0;cursor:pointer;font-size:14px;line-height:1';
    remove.onclick = function(){ removeRecipient(index); };
    item.appendChild(text);
    item.appendChild(remove);
    list.appendChild(item);
  });
}

function syncRecipients(emails) {
  var unique = [];
  var seen = {};
  emails.forEach(function(email){
    var key = String(email).toLowerCase();
    if (seen[key]) return;
    seen[key] = true;
    unique.push(email);
  });
  document.getElementById('sendRecipients').value = unique.join('\n');
  renderRecipients(unique);
}

function currentRecipients() {
  return parseRecipientsText(document.getElementById('sendRecipients').value);
}

function addManualRecipient() {
  var input = document.getElementById('manualRecipient');
  var email = input.value.trim();
  if (!email) return;
  var emails = currentRecipients();
  emails.push(email);
  syncRecipients(emails);
  input.value = '';
}

function removeRecipient(index) {
  var emails = currentRecipients();
  emails.splice(index, 1);
  syncRecipients(emails);
}

function clearRecipients() {
  syncRecipients([]);
}

function loadExportContent(id) {
  if (!id) {
    document.getElementById('sendContent').value = '';
    syncRecipients([]);
    return;
  }
  fetch('?page=api_export_content&id=' + encodeURIComponent(id) + '&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (!data.ok) return;
      var raw = data.content || '';
      document.getElementById('sendContent').value = raw;
      syncRecipients(raw.split(/\r?\n/).map(function(line){
        var qq = String(line || '').trim();
        if (!qq) return '';
        return qq + '@qq.com';
      }).filter(Boolean));
    });
}

var STATUS_MAP = {pending:'待处理', running:'执行中', done:'已完成', partial:'已完成(部分失败)', cancelled:'已取消'};
var STATUS_COLOR = {pending:'#1769e0', running:'#e08a17', done:'#1a9d5c', partial:'#c73d3d', cancelled:'#9aa7b6'};

function badge(status) {
  return '<span class="mail-status mail-status-' + escapeHtml(status) + '">' + (STATUS_MAP[status] || status) + '</span>';
}

function fmtTime(ts) {
  if (!ts) return '-';
  var d = new Date(ts * 1000);
  function p(n){ return n < 10 ? '0' + n : n; }
  return '<span>' + d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + '</span><small>' + p(d.getHours()) + ':' + p(d.getMinutes()) + '</small>';
}

function renderTasks(jobs) {
  var body = document.querySelector('#taskTable tbody');
  if (!jobs.length) {
    body.innerHTML = '<tr><td colspan="6" class="mail-task-empty">还没有发信任务</td></tr>';
    return;
  }
  var now = Math.floor(Date.now()/1000);
  body.innerHTML = jobs.map(function(j){
    var expire = '';
    if (j.status === 'running' && (j.execution_deadline || 0) > 0) {
      var executionLeft = Math.max(0, j.execution_deadline - now);
      var hours = Math.floor(executionLeft / 3600);
      var minutes = Math.ceil((executionLeft % 3600) / 60);
      expire = '<span class="mail-expire ' + (executionLeft < 3600 ? 'ending' : '') + '">剩 ' + hours + '小时' + minutes + '分</span>';
    } else if (j.status === 'pending') {
      var left = Math.max(0, (j.task_key_expires_at || 0) - now);
      expire = '<span class="mail-expire ' + (left < 300 ? 'ending' : '') + '">' + Math.ceil(left/60) + ' 分钟</span>';
    } else {
      expire = '<span class="mail-muted">-</span>';
    }
    var key = j.task_key ? '<button type="button" class="mail-task-key" title="点击复制任务密钥" onclick="copyTaskKey(\'' + escapeJs(j.task_key) + '\',this)">' + escapeHtml(j.task_key) + '<span>复制</span></button>' : '<span class="mail-muted">-</span>';
    var progress = '<div class="mail-progress"><strong>' + (j.sent||0) + '<small>成功</small></strong><span class="failed">' + (j.failed||0) + '<small>失败</small></span><span>' + (j.remaining||0) + '<small>剩余</small></span><em>/ ' + (j.total||0) + '</em></div>';
    return '<tr><td class="mail-task-subject" title="' + escapeHtml(j.subject) + '">' + escapeHtml(j.subject) + '</td><td>' + key + '</td><td>' + badge(j.status) + '</td><td>' + progress + '</td><td class="mail-task-time">' + fmtTime(j.created_at) + '</td><td>' + expire + '</td></tr>';
  }).join('');
}

function escapeHtml(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escapeJs(s) {
  return String(s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r/g, '').replace(/\n/g, '');
}

function copyTaskKey(key, button) {
  function copied() {
    var label = button ? button.querySelector('span') : null;
    if (label) {
      label.textContent = '已复制';
      setTimeout(function(){ label.textContent = '复制'; }, 1200);
    }
    toast('任务密钥已复制');
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(key).then(copied).catch(function(){ fallbackCopy(key, copied); });
  } else {
    fallbackCopy(key, copied);
  }
}

function fallbackCopy(text, done) {
  var input = document.createElement('textarea');
  input.value = text;
  input.style.cssText = 'position:fixed;left:-9999px;top:0';
  document.body.appendChild(input);
  input.select();
  try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败，请长按密钥复制'); }
  document.body.removeChild(input);
}

function refreshTasks() {
  fetch('?page=api_task_stats&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (data.ok) {
        renderTasks(data.jobs || []);
        document.getElementById('refreshHint').textContent = '更新于 ' + new Date().toLocaleTimeString();
      }
    })
    .catch(function(){});
}

renderRecipients(currentRecipients());
refreshTasks();
setInterval(refreshTasks, 5000);

function previewMail() {
  var subject = document.getElementById('sendSubject') ? document.getElementById('sendSubject').value : '';
  var html = document.getElementById('sendHtml') ? document.getElementById('sendHtml').value : '';
  var frame = document.getElementById('previewFrame');
  frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Arial,"Microsoft YaHei",sans-serif;padding:18px;color:#1a2a3a;line-height:1.7;word-break:break-word}</style></head><body>' + html + '</body></html>';
  document.getElementById('previewSubject').textContent = subject ? '主题：' + subject : '（主题留空）';
  document.getElementById('previewModal').classList.add('show');
}
function closePreview() { document.getElementById('previewModal').classList.remove('show'); }
</script>
