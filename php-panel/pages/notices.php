<?php
if (!is_admin()) { echo '<div class="empty">仅管理员可访问</div>'; return; }

// 保存公告
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'notice_add') {
    $title = mb_substr(trim($_POST['title'] ?? ''), 0, 100);
    $content = trim($_POST['content'] ?? '');
    $expires = intval($_POST['expires_at'] ?? 0);
    if ($title === '' || $content === '') {
      $error = '标题和内容不能为空';
    } else {
      db()->prepare('INSERT INTO platform_notices(title,content,status,created_at,expires_at) VALUES(?,?,?,?,?)')
        ->execute([$title, $content, 'active', now(), $expires]);
      $message = '公告已发布';
    }
  } elseif ($action === 'notice_disable') {
    $id = intval($_POST['id'] ?? 0);
    db()->prepare("UPDATE platform_notices SET status='disabled' WHERE id=?")->execute([$id]);
    $message = '公告已下架';
  } elseif ($action === 'notice_delete') {
    $id = intval($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM platform_notices WHERE id=?')->execute([$id]);
    $message = '公告已删除';
  }
  if (empty($message)) $message = '';
}

$notices = db()->query('SELECT id,title,content,status,created_at,expires_at FROM platform_notices ORDER BY created_at DESC LIMIT 100')->fetchAll();
$now = now();
?>
<h2 class="page-title">平台公告</h2>
<?php if (!empty($message)): ?><div class="card" style="border:1px solid #2f9e5f;background:#f2fbf5;color:#1d7a42"><b><?=e($message)?></b></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="card" style="border:1px solid #c73d3d;background:#fdf2f2;color:#c73d3d"><?=e($error)?></div><?php endif; ?>

<div class="card" style="max-width:560px">
  <h3 style="margin-bottom:14px">发布公告</h3>
  <form method="post">
    <input type="hidden" name="action" value="notice_add">
    <div class="form-group">
      <label>标题</label>
      <input name="title" required maxlength="100" placeholder="例如：系统维护通知" style="width:100%">
    </div>
    <div class="form-group">
      <label>内容</label>
      <textarea name="content" required rows="4" placeholder="公告详细内容..." style="width:100%"></textarea>
    </div>
    <div class="form-group">
      <label>过期时间（Unix 时间戳，0 表示永不过期）</label>
      <input name="expires_at" type="number" min="0" value="0" placeholder="0" style="width:100%">
    </div>
    <button type="submit" class="btn">发布公告</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:14px">公告列表</h3>
  <?php if (!$notices): ?>
    <div class="empty">暂无公告</div>
  <?php else: ?>
    <?php foreach ($notices as $n): $active = $n['status'] === 'active' && ($n['expires_at'] == 0 || $n['expires_at'] > $now); ?>
    <div style="padding:14px 0;border-bottom:1px solid #f0f3f7">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <strong><?=e($n['title'])?></strong>
          <?php if ($active): ?>
            <span style="background:#e8f7f0;color:#1d7a42;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px">展示中</span>
          <?php else: ?>
            <span style="background:#f0f3f7;color:#9aa7b6;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px">已下架</span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:#9aa7b6"><?=date('Y-m-d H:i', $n['created_at'])?></div>
      </div>
      <div style="color:#687789;font-size:13px;margin-top:6px;white-space:pre-wrap"><?=e(mb_strimwidth($n['content'], 0, 200, '...'))?></div>
      <div style="margin-top:10px">
        <?php if ($n['status'] === 'active'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="notice_disable"><input type="hidden" name="id" value="<?=$n['id']?>"><button type="submit" class="btn btn-sm btn-gray">下架</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="notice_delete"><input type="hidden" name="id" value="<?=$n['id']?>"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确认删除该公告？')">删除</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
