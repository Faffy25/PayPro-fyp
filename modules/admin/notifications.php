<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$pageTitle = 'Notifications';
$uid       = current_user()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'read_all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
        set_flash('success', 'All notifications marked as read.');
    }
    if (($_POST['action'] ?? '') === 'clear') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1')->execute([$uid]);
        set_flash('success', 'Read notifications cleared.');
    }
    redirect('modules/admin/notifications.php');
}

// Opening the page marks a single notification as read when followed by link.
if (isset($_GET['open'])) {
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE notification_id = ? AND user_id = ?');
    $stmt->execute([(int)$_GET['open'], $uid]);
    if ($n = $stmt->fetch()) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ?')->execute([$n['notification_id']]);
        if ($n['link']) redirect($n['link']);
    }
    redirect('modules/admin/notifications.php');
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$uid]);
$items = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= count($items) ?> notification<?= count($items) === 1 ? '' : 's' ?></h2>
    <div class="spacer"></div>
    <form method="post" style="display:flex;gap:.4rem">
      <?= csrf_field() ?>
      <button class="btn secondary small" name="action" value="read_all">Mark all read</button>
      <button class="btn secondary small" name="action" value="clear" data-confirm="Delete every notification you have already read?">Clear read</button>
    </form>
  </div>

  <?php if (!$items): ?>
    <div class="panel-body muted">Nothing yet. Leave decisions, approved appraisals and payroll runs show up here.</div>
  <?php endif; ?>

  <?php foreach ($items as $n): ?>
    <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
      <div style="flex:1">
        <strong><?= e($n['title']) ?></strong>
        <?php if (!$n['is_read']): ?><span class="badge ok">New</span><?php endif; ?>
        <div class="small muted"><?= e($n['message']) ?></div>
        <?php if ($n['link']): ?>
          <a class="small" href="<?= url('modules/admin/notifications.php?open=' . $n['notification_id']) ?>">Open</a>
        <?php endif; ?>
      </div>
      <div class="when"><?= fdate($n['created_at'], 'd M H:i') ?></div>
    </div>
  <?php endforeach; ?>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
