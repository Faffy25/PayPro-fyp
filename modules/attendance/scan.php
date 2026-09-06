<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$pageTitle = 'Scan attendance';
$me        = my_employee();
$token     = $_GET['t'] ?? ($_POST['t'] ?? '');
$validToken = $token !== '' && hash_equals(attendance_token(date('Y-m-d')), $token);

$error  = null;
$result = null;

if (!$me) {
    $error = 'This account is not linked to an employee record, so attendance cannot be recorded. Ask HR to link it.';
} elseif (!$validToken) {
    $error = 'This QR code is not valid for today. Ask HR to show today\'s attendance code and scan it again.';
}

/* Current state, used to label the button and after a successful scan. */
$todayRow = null;
if ($me) {
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()');
    $stmt->execute([$me['employee_id']]);
    $todayRow = $stmt->fetch();
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $result = mark_attendance_scan($me);
    if ($result['type'] === 'in') {
        set_flash('success', $result['message']);
    }
}

$nextAction = !$todayRow ? 'Clock in' : (empty($todayRow['time_out']) ? 'Clock out' : null);

include APP_ROOT . '/includes/header.php';
?>

<div style="max-width:460px;margin:0 auto">
<?php if ($error): ?>

  <section class="panel">
    <div class="panel-head"><h2>Attendance</h2></div>
    <div class="panel-body">
      <div class="alert error" style="margin:0"><?= e($error) ?></div>
      <p class="muted small" style="margin-top:1rem">
        <a href="<?= url('modules/attendance/index.php') ?>">Go to attendance</a>
      </p>
    </div>
  </section>

<?php elseif ($result): ?>

  <section class="panel">
    <div class="panel-head">
      <h2><?= $result['type'] === 'done' ? 'Already recorded' : 'Attendance recorded' ?></h2>
      <div class="spacer"></div>
      <?= status_badge($result['status']) ?>
    </div>
    <div class="panel-body stack">
      <p style="font-size:1.05rem;margin:0"><?= e($result['message']) ?></p>
      <div class="metrics" style="margin:0">
        <div class="metric">
          <div class="label">Clock in</div>
          <div class="value"><?= e($result['time_in'] ?: '—') ?></div>
        </div>
        <div class="metric <?= !empty($result['time_out']) ? 'blue' : '' ?>">
          <div class="label">Clock out</div>
          <div class="value"><?= e($result['time_out'] ?: '—') ?></div>
        </div>
        <?php if (isset($result['hours'])): ?>
        <div class="metric gold">
          <div class="label">Hours worked</div>
          <div class="value"><?= e(money($result['hours'])) ?></div>
          <?php if (!empty($result['overtime'])): ?>
            <div class="note"><?= e(money($result['overtime'])) ?> overtime</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <p class="muted small" style="margin:0">
        <?= e($me['first_name'] . ' ' . $me['last_name']) ?> &middot; <?= e(date('l, j F Y')) ?>
      </p>
      <div><a class="btn secondary" href="<?= url('modules/attendance/index.php') ?>">View my attendance</a></div>
    </div>
  </section>

<?php else: ?>

  <section class="panel">
    <div class="panel-head">
      <h2>Confirm attendance</h2>
      <div class="spacer"></div>
      <span class="muted small"><?= e(date('H:i')) ?></span>
    </div>
    <div class="panel-body stack">
      <p style="margin:0">
        Hi <strong><?= e($me['first_name']) ?></strong>. Today is <?= e(date('l, j F Y')) ?>.
      </p>
      <div class="metrics" style="margin:0">
        <div class="metric">
          <div class="label">Clock in</div>
          <div class="value"><?= $todayRow && $todayRow['time_in'] ? e(substr($todayRow['time_in'], 0, 5)) : '—' ?></div>
        </div>
        <div class="metric blue">
          <div class="label">Clock out</div>
          <div class="value"><?= $todayRow && $todayRow['time_out'] ? e(substr($todayRow['time_out'], 0, 5)) : '—' ?></div>
        </div>
      </div>

      <?php if ($nextAction === null): ?>
        <div class="alert info" style="margin:0">You have already clocked in and out today.</div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <button class="btn" style="width:100%;justify-content:center;padding:.7rem">
            <?= e($nextAction) ?> now
          </button>
        </form>
        <p class="hint" style="margin:0">Clocking in after 09:00 is recorded as late.</p>
      <?php endif; ?>
    </div>
  </section>

<?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
