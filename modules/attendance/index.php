<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$pageTitle = has_role(['admin','hr','manager']) ? 'Attendance' : 'My attendance';
$me        = my_employee();
$date      = $_GET['date'] ?? date('Y-m-d');
$isOfficer = has_role(['admin','hr','manager']);

/* ------------------------------------------------------------------
   Actions
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // --- Employee clocks themselves in or out ---------------------
    if ($action === 'clock_in' && $me) {
        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()');
        $stmt->execute([$me['employee_id']]);
        if ($stmt->fetch()) {
            set_flash('info', 'You have already clocked in today.');
        } else {
            $status = (int)date('H') >= 9 ? 'Late' : 'Present';
            $pdo->prepare('INSERT INTO attendance (employee_id, work_date, time_in, status) VALUES (?, CURDATE(), CURTIME(), ?)')
                ->execute([$me['employee_id'], $status]);
            log_action('CREATE', 'Attendance', (int)$pdo->lastInsertId(), 'Clocked in at ' . date('H:i'));
            set_flash('success', 'Clocked in at ' . date('H:i') . '.');
        }
    }

    if ($action === 'clock_out' && $me) {
        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()');
        $stmt->execute([$me['employee_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            set_flash('error', 'Clock in first, then clock out.');
        } elseif ($row['time_out']) {
            set_flash('info', 'You already clocked out at ' . substr($row['time_out'], 0, 5) . '.');
        } else {
            $hours    = round((strtotime(date('H:i:s')) - strtotime($row['time_in'])) / 3600, 2);
            $standard = (float)setting('working_hours', 8);
            $overtime = max(0, round($hours - $standard, 2));
            $pdo->prepare('UPDATE attendance SET time_out = CURTIME(), hours_worked = ?, overtime_hours = ? WHERE attendance_id = ?')
                ->execute([$hours, $overtime, $row['attendance_id']]);
            log_action('UPDATE', 'Attendance', (int)$row['attendance_id'], 'Clocked out after ' . $hours . ' hours');
            set_flash('success', 'Clocked out. ' . $hours . ' hours recorded' . ($overtime ? ', including ' . $overtime . ' overtime.' : '.'));
        }
    }

    // --- HR marks the whole register for a date -------------------
    if ($action === 'bulk_save' && $isOfficer) {
        $day    = $_POST['work_date'];
        $rows   = $_POST['row'] ?? [];
        $saved  = 0;
        $upsert = $pdo->prepare(
            'INSERT INTO attendance (employee_id, work_date, time_in, time_out, hours_worked, overtime_hours, status, remarks)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), time_out = VALUES(time_out),
               hours_worked = VALUES(hours_worked), overtime_hours = VALUES(overtime_hours),
               status = VALUES(status), remarks = VALUES(remarks)'
        );
        foreach ($rows as $empId => $r) {
            if (($r['status'] ?? '') === '') continue;
            $in    = $r['time_in']  ?: null;
            $out   = $r['time_out'] ?: null;
            $hours = ($in && $out) ? round((strtotime($out) - strtotime($in)) / 3600, 2) : 0;
            $ot    = max(0, round($hours - (float)setting('working_hours', 8), 2));
            $upsert->execute([(int)$empId, $day, $in, $out, $hours, $ot, $r['status'], $r['remarks'] ?? null]);
            $saved++;
        }
        log_action('UPDATE', 'Attendance', null, "Saved register for $day ($saved employees)");
        set_flash('success', "Register saved for $saved employee" . ($saved === 1 ? '' : 's') . '.');
    }
    redirect('modules/attendance/index.php?date=' . urlencode($_POST['work_date'] ?? $date));
}

/* ------------------------------------------------------------------
   Page data
------------------------------------------------------------------ */
$today = null;
if ($me) {
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()');
    $stmt->execute([$me['employee_id']]);
    $today = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? ORDER BY work_date DESC LIMIT 15');
    $stmt->execute([$me['employee_id']]);
    $myHistory = $stmt->fetchAll();
}

if ($isOfficer) {
    $sql  = "SELECT e.employee_id, e.emp_number, e.first_name, e.last_name, d.dept_name,
                    a.time_in, a.time_out, a.hours_worked, a.status, a.remarks
             FROM employees e
             LEFT JOIN departments d ON d.department_id = e.department_id
             LEFT JOIN attendance a ON a.employee_id = e.employee_id AND a.work_date = ?
             WHERE e.status = 'Active'";
    $args = [$date];
    if (has_role('manager')) {
        $sql   .= ' AND (e.manager_id = ? OR e.employee_id = ?)';
        $args[] = current_user()['employee_id'];
        $args[] = current_user()['employee_id'];
    }
    $sql .= ' ORDER BY e.emp_number';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $register = $stmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<?php if ($me): ?>
<section class="panel">
  <div class="panel-head">
    <h2>Today &middot; <?= e(date('j F Y')) ?></h2>
    <div class="spacer"></div>
    <span class="muted small">
      In: <?= $today && $today['time_in'] ? e(substr($today['time_in'], 0, 5)) : '—' ?> &nbsp;
      Out: <?= $today && $today['time_out'] ? e(substr($today['time_out'], 0, 5)) : '—' ?>
    </span>
  </div>
  <div class="panel-body" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="clock_in">
      <button class="btn" <?= $today ? 'disabled' : '' ?>>Clock in</button>
    </form>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="clock_out">
      <button class="btn secondary" <?= (!$today || $today['time_out']) ? 'disabled' : '' ?>>Clock out</button>
    </form>
    <span class="hint">Clocking in after 09:00 is recorded as late.</span>
  </div>
</section>
<?php endif; ?>

<?php if ($isOfficer): ?>
<section class="panel">
  <div class="panel-head">
    <h2>Daily register</h2>
    <div class="spacer"></div>
    <form method="get" class="filters">
      <div class="field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field"><label>&nbsp;</label><button class="btn secondary">Load</button></div>
    </form>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_save">
    <input type="hidden" name="work_date" value="<?= e($date) ?>">
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr><th>Employee</th><th>Department</th><th>Status</th><th>Time in</th><th>Time out</th><th>Remarks</th></tr>
        </thead>
        <tbody>
        <?php foreach ($register as $r): ?>
          <tr>
            <td class="strong"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
                <span class="small muted"><?= e($r['emp_number']) ?></span></td>
            <td><?= e($r['dept_name'] ?? '—') ?></td>
            <td>
              <select name="row[<?= $r['employee_id'] ?>][status]">
                <option value="">Not marked</option>
                <?php foreach (['Present','Late','Absent','On Leave','Half Day'] as $s): ?>
                  <option value="<?= $s ?>" <?= ($r['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="time" name="row[<?= $r['employee_id'] ?>][time_in]"  value="<?= e(substr((string)$r['time_in'], 0, 5)) ?>"></td>
            <td><input type="time" name="row[<?= $r['employee_id'] ?>][time_out]" value="<?= e(substr((string)$r['time_out'], 0, 5)) ?>"></td>
            <td><input type="text" name="row[<?= $r['employee_id'] ?>][remarks]" value="<?= e($r['remarks']) ?>" placeholder="Optional"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-body">
      <button class="btn">Save register</button>
      <span class="hint">Hours worked and overtime are calculated from the times you enter.</span>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($me): ?>
<section class="panel">
  <div class="panel-head"><h2>My last 15 days</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="num">Hours</th><th class="num">Overtime</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$myHistory): ?><tr><td colspan="6" class="muted">No attendance recorded yet.</td></tr><?php endif; ?>
      <?php foreach ($myHistory as $h): ?>
        <tr>
          <td class="nowrap"><?= fdate($h['work_date']) ?></td>
          <td><?= e(substr((string)$h['time_in'], 0, 5) ?: '—') ?></td>
          <td><?= e(substr((string)$h['time_out'], 0, 5) ?: '—') ?></td>
          <td class="num"><?= money($h['hours_worked']) ?></td>
          <td class="num"><?= money($h['overtime_hours']) ?></td>
          <td><?= status_badge($h['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
