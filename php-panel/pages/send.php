<?php
try {
  $configs = node_api('GET', '/api/smtp/configs')['configs'] ?? [];
  $exports = db()->query('SELECT * FROM export_records ORDER BY created_at DESC LIMIT 200')->fetchAll();
  $exports = array_values(array_filter($exports, function ($item) {
    return intval($item['line_count'] ?? 0) > 0;
  }));
} catch (Exception $e) { echo '<p style="color:#c73d3d">' . e($e->getMessage()) . '</p>'; return; }
?>
<h2 class="page-title">发送邮件</h2>

<div class="card">
<form method="post" id="sendForm" onsubmit="return doSend(event)">
  <input type="hidden" name="action" value="send">

  <div class="form-group"><label>SMTP 配置</label>
    <select name="config_id" id="smtpConfig" required>
      <option value="">-- 选择 --</option>
      <?php foreach ($configs as $c): ?>
      <option value="<?=e($c['id'])?>"><?=e($c['from_name']?:$c['from_email'])?> (<?=e($c['host'])?>)</option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>选择导出记录</label>
    <select name="export_id" id="exportSelect" onchange="loadExportContent(this.value)">
      <option value="">-- 选择导出记录 --</option>
      <option value="custom">自定义内容</option>
      <?php foreach ($exports as $r): ?>
      <option value="<?=intval($r['id'])?>">[<?=$r['export_type']==='friends'?'好友':'群成员'?>] <?=e($r['account_uin'])?> - <?=intval($r['line_count'])?>条 (<?=date('m-d H:i',$r['created_at'])?>)</option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>收件人邮箱</label>
    <textarea name="recipients" id="sendRecipients" rows="6" placeholder="这里保存最终收件人列表，每行一个邮箱" required></textarea>
  </div>
  <div class="form-group">
    <label>收件人列表编辑</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <input id="manualRecipient" placeholder="手动添加邮箱，例如 123456@qq.com" style="flex:1;min-width:220px">
      <button type="button" class="btn btn-gray" onclick="addManualRecipient()">添加邮箱</button>
      <button type="button" class="btn btn-gray" onclick="clearRecipients()">清空</button>
    </div>
    <div id="recipientList" style="display:flex;flex-wrap:wrap;gap:8px;padding:10px;border:1px solid #d8dee6;border-radius:8px;background:#fff;min-height:48px"></div>
    <div id="recipientCount" style="margin-top:8px;color:#6d7c8d;font-size:12px">当前收件人：0</div>
  </div>

  <div class="form-group"><label>邮件主题</label>
    <input name="subject" id="sendSubject" placeholder="留空自动生成"></div>

  <div class="form-group"><label>邮件正文（支持 HTML，可放图片链接、超链接）</label>
    <textarea name="html" id="sendHtml" rows="10" placeholder="例如：&lt;p&gt;您好，这里是正文说明。&lt;/p&gt;&lt;p&gt;&lt;a href=&quot;https://example.com&quot;&gt;点击查看&lt;/a&gt;&lt;/p&gt;&lt;img src=&quot;https://example.com/a.jpg&quot; alt=&quot;image&quot; style=&quot;max-width:100%&quot;&gt;"></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:6px"><button type="button" class="btn btn-sm btn-gray" onclick="previewMail()">👁 预览效果</button></div>
  </div>

  <textarea name="content" id="sendContent" rows="10" style="display:none"></textarea>
  <div style="margin:-8px 0 14px;color:#6d7c8d;font-size:12px">说明：收件人会按“QQ号@qq.com”自动生成；邮件主题在上，邮件正文在中间填写。</div>

  <button type="submit" class="btn" id="sendBtn">发送</button>
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
  if (!email) { toast('请输入邮箱'); return; }
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
  if (!id || id === 'custom') {
    document.getElementById('sendContent').value = '';
    syncRecipients([]);
    document.getElementById('sendSubject').value = '';
    return;
  }
  fetch('?page=api_export_content&id=' + encodeURIComponent(id) + '&t=' + Date.now())
    .then(function(r){return r.json()})
    .then(function(data){
      if (data.ok) {
        var raw = data.content || '';
        document.getElementById('sendContent').value = raw;
        syncRecipients(raw.split(/\r?\n/).map(function(line){
          var qq = String(line || '').trim();
          if (!qq) return '';
          return qq + '@qq.com';
        }).filter(Boolean));
        if (!document.getElementById('sendSubject').value)
          document.getElementById('sendSubject').value = 'QQ号列表 (' + data.line_count + ' 条)';
      } else {
        toast(data.error || '加载失败');
      }
    }).catch(function(){toast('请求失败')});
}

function doSend(e) {
  e.preventDefault();
  var config_id = document.getElementById('smtpConfig').value;
  var recipients = document.getElementById('sendRecipients').value.trim();
  var subject = document.getElementById('sendSubject').value.trim() || 'QQ号列表';
  var content = document.getElementById('sendContent').value.trim();
  var html = document.getElementById('sendHtml').value.trim();
  if (!config_id) { toast('请选择 SMTP 配置'); return false; }
  if (!recipients) { toast('请先选择导出记录或手动填写收件人邮箱'); return false; }
  var btn = document.getElementById('sendBtn');
  btn.disabled = true;
  btn.textContent = '发送中...';
  var form = new URLSearchParams();
  form.append('config_id', config_id);
  form.append('export_id', document.getElementById('exportSelect').value);
  form.append('recipients', recipients);
  form.append('subject', subject);
  form.append('content', content);
  form.append('html', html);
  fetch('?page=api_send', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()})
    .then(function(r){return r.json()})
    .then(function(data){
      btn.disabled = false;
      btn.textContent = '发送';
      if (data.ok) {
        toast('已创建发信任务，收件人数：' + (data.recipient_count || 0));
        syncRecipients(currentRecipients());
      } else {
        toast(data.error || '发送失败');
      }
    }).catch(function(){
      btn.disabled = false;
      btn.textContent = '发送';
      toast('请求失败');
    });
  return false;
}
renderRecipients(currentRecipients());

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
