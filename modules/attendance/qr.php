<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin', 'hr']);

$pageTitle = 'Attendance QR';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'rotate') {
        set_setting('attendance_qr_secret', bin2hex(random_bytes(16)), 'Signing key for the attendance QR code');
        log_action('UPDATE', 'Attendance', null, 'Rotated the attendance QR code');
        set_flash('success', 'A new QR code has been generated. The previous one no longer works.');
    }
    redirect('modules/attendance/qr.php');
}

$today   = date('Y-m-d');
$scanUrl = abs_url('modules/attendance/scan.php') . '?t=' . attendance_token($today);

$register = $pdo->query(
    "SELECT e.emp_number, e.first_name, e.last_name, d.dept_name,
            a.time_in, a.time_out, a.status
     FROM attendance a
     JOIN employees e   ON e.employee_id = a.employee_id
     LEFT JOIN departments d ON d.department_id = e.department_id
     WHERE a.work_date = CURDATE()
     ORDER BY a.time_in DESC, e.emp_number"
)->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head">
      <h2>Today &middot; <?= e(date('j F Y')) ?></h2>
    </div>
    <div class="panel-body stack" style="text-align:center">
      <p class="muted" style="margin:0 auto">
        Display this code at the entrance. Staff scan it with their phone camera and confirm
        to clock in; scanning again later clocks them out.
      </p>
      <div id="qr" style="display:inline-block;padding:14px;background:#fff;border:1px solid var(--line);border-radius:var(--radius)"></div>
      <p class="small" style="margin:0;word-break:break-all">
        <a href="<?= e($scanUrl) ?>"><?= e($scanUrl) ?></a>
      </p>
      <div class="no-print" style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap">
        <button class="btn secondary" onclick="window.print()">Print</button>
        <form method="post" data-confirm="Generate a new code? Any printed copies of the current one will stop working.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rotate">
          <button class="btn secondary">Rotate code</button>
        </form>
      </div>
      <p class="hint" style="margin:0">
        The code changes automatically each day, so a photo taken yesterday will not work.
      </p>
    </div>
  </section>

  <section class="panel no-print">
    <div class="panel-head">
      <h2>Clocked in today</h2>
      <div class="spacer"></div>
      <a class="btn secondary small no-print" href="<?= url('modules/attendance/qr.php') ?>">Refresh</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr><th>Employee</th><th>Department</th><th>In</th><th>Out</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (!$register): ?>
            <tr><td colspan="5" class="muted">No one has scanned in yet today.</td></tr>
          <?php endif; ?>
          <?php foreach ($register as $r): ?>
            <tr>
              <td class="strong"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
                  <span class="small muted"><?= e($r['emp_number']) ?></span></td>
              <td><?= e($r['dept_name'] ?? '—') ?></td>
              <td><?= e(substr((string)$r['time_in'], 0, 5) ?: '—') ?></td>
              <td><?= e(substr((string)$r['time_out'], 0, 5) ?: '—') ?></td>
              <td><?= status_badge($r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  var box = document.getElementById('qr');
  if (!box || typeof QRCode === 'undefined') {
    if (box) box.innerHTML = '<p class="small muted">QR image could not load. Staff can open the link above instead.</p>';
    return;
  }
  new QRCode(box, {
    text: <?= json_encode($scanUrl) ?>,
    width: 220,
    height: 220,
    correctLevel: QRCode.CorrectLevel.M
  });
})();
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
