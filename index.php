<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pageTitle = 'Dashboard';
$me        = current_user();
$cur       = setting('currency', 'USD');

/* ---------------- Data for managers, HR and admin ---------------- */
if (has_role(['admin', 'hr', 'manager'])) {
    $headcount   = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
    $pendingLeave= (int)$pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'Pending'")->fetchColumn();
    $openAppr    = (int)$pdo->query("SELECT COUNT(*) FROM performance_appraisals WHERE status <> 'Approved'")->fetchColumn();
    $presentToday= (int)$pdo->query(
        "SELECT COUNT(*) FROM attendance WHERE work_date = CURDATE() AND status IN ('Present','Late','Half Day')"
    )->fetchColumn();

    $lastRun = $pdo->query(
        "SELECT p.*, COALESCE(SUM(s.net_pay),0) AS total_net, COUNT(s.payslip_id) AS slips
         FROM payroll_periods p LEFT JOIN payslips s ON s.payroll_period_id = p.payroll_period_id
         GROUP BY p.payroll_period_id ORDER BY p.period_year DESC, p.period_month DESC LIMIT 1"
    )->fetch();

    $trend = $pdo->query(
        "SELECT p.period_month, p.period_year,
                SUM(s.gross_pay) AS gross, SUM(s.net_pay) AS net, SUM(s.performance_bonus) AS bonus
         FROM payroll_periods p JOIN payslips s ON s.payroll_period_id = p.payroll_period_id
         GROUP BY p.payroll_period_id ORDER BY p.period_year DESC, p.period_month DESC LIMIT 6"
    )->fetchAll();
    $trend = array_reverse($trend);

    $byDept = $pdo->query(
        "SELECT d.dept_name, COUNT(e.employee_id) AS n
         FROM departments d LEFT JOIN employees e
           ON e.department_id = d.department_id AND e.status = 'Active'
         GROUP BY d.department_id ORDER BY n DESC"
    )->fetchAll();

    $recentLeave = $pdo->query(
        "SELECT l.*, e.first_name, e.last_name, t.type_name
         FROM leave_applications l
         JOIN employees e ON e.employee_id = l.employee_id
         JOIN leave_types t ON t.leave_type_id = l.leave_type_id
         ORDER BY l.applied_on DESC LIMIT 6"
    )->fetchAll();
}

/* ---------------- Data for the signed-in employee ---------------- */
$emp = my_employee();
if ($emp) {
    $stmt = $pdo->prepare(
        "SELECT s.*, p.period_month, p.period_year FROM payslips s
         JOIN payroll_periods p ON p.payroll_period_id = s.payroll_period_id
         WHERE s.employee_id = ? ORDER BY p.period_year DESC, p.period_month DESC LIMIT 1"
    );
    $stmt->execute([$emp['employee_id']]);
    $myPayslip = $stmt->fetch();

    $myAppraisal = latest_approved_appraisal((int)$emp['employee_id']);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(days_requested),0) FROM leave_applications
         WHERE employee_id = ? AND status = 'Approved' AND YEAR(start_date) = YEAR(CURDATE())"
    );
    $stmt->execute([$emp['employee_id']]);
    $leaveTaken = (int)$stmt->fetchColumn();
    $annualDays = (int)$pdo->query("SELECT days_per_year FROM leave_types WHERE type_name = 'Annual Leave'")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance WHERE employee_id = ?
         AND MONTH(work_date) = MONTH(CURDATE()) AND YEAR(work_date) = YEAR(CURDATE())
         AND status IN ('Present','Late','Half Day')"
    );
    $stmt->execute([$emp['employee_id']]);
    $daysThisMonth = (int)$stmt->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>

<p class="muted" style="margin-top:-.35rem">
  <?= e(date('l, j F Y')) ?> &nbsp;|&nbsp; Signed in as <?= e($me['full_name']) ?> (<?= e($me['role_name']) ?>)
</p>

<?php if (has_role(['admin','hr','manager'])): ?>
<div class="metrics">
  <div class="metric">
    <div class="label">Active employees</div>
    <div class="value"><?= $headcount ?></div>
    <div class="note"><?= $presentToday ?> clocked in today</div>
  </div>
  <div class="metric gold">
    <div class="label">Leave awaiting a decision</div>
    <div class="value"><?= $pendingLeave ?></div>
    <div class="note"><a href="<?= url('modules/leave/index.php?status=Pending') ?>">Review requests</a></div>
  </div>
  <div class="metric blue">
    <div class="label">Appraisals not yet approved</div>
    <div class="value"><?= $openAppr ?></div>
    <div class="note"><a href="<?= url('modules/performance/index.php') ?>">Open appraisals</a></div>
  </div>
  <div class="metric">
    <div class="label">Last payroll run</div>
    <div class="value"><?= $lastRun ? $cur . ' ' . money($lastRun['total_net']) : '&mdash;' ?></div>
    <div class="note">
      <?= $lastRun ? e(month_name((int)$lastRun['period_month'])) . ' ' . (int)$lastRun['period_year']
          . ' &middot; ' . (int)$lastRun['slips'] . ' payslips' : 'No payroll processed yet' ?>
    </div>
  </div>
</div>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Payroll cost over recent runs</h2></div>
    <div class="panel-body">
      <?php if ($trend): ?>
        <canvas id="payrollChart" height="150"></canvas>
      <?php else: ?>
        <p class="muted">No payroll has been processed yet. Start a run to see costs plotted here.</p>
        <a class="btn" href="<?= url('modules/payroll/index.php') ?>">Go to payroll runs</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Headcount by department</h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Department</th><th class="num">Staff</th><th style="width:40%">Share</th></tr></thead>
        <tbody>
        <?php foreach ($byDept as $d):
              $pct = $headcount ? round($d['n'] / $headcount * 100) : 0; ?>
          <tr>
            <td><?= e($d['dept_name']) ?></td>
            <td class="num"><?= (int)$d['n'] ?></td>
            <td><div class="progress"><span style="width:<?= $pct ?>%"></span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<section class="panel">
  <div class="panel-head">
    <h2>Latest leave requests</h2>
    <div class="spacer"></div>
    <a class="btn secondary small" href="<?= url('modules/leave/index.php') ?>">All requests</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$recentLeave): ?>
        <tr><td colspan="5" class="muted">Nothing submitted yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recentLeave as $l): ?>
        <tr>
          <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td>
          <td><?= e($l['type_name']) ?></td>
          <td class="nowrap"><?= fdate($l['start_date']) ?> &ndash; <?= fdate($l['end_date']) ?></td>
          <td class="num"><?= (int)$l['days_requested'] ?></td>
          <td><?= status_badge($l['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($emp): ?>
<h2 style="margin-top:1.5rem">My record</h2>
<div class="metrics">
  <div class="metric">
    <div class="label">Latest net pay</div>
    <div class="value"><?= $myPayslip ? $cur . ' ' . money($myPayslip['net_pay']) : '&mdash;' ?></div>
    <div class="note">
      <?= $myPayslip ? e(month_name((int)$myPayslip['period_month'])) . ' ' . (int)$myPayslip['period_year'] : 'No payslip issued yet' ?>
    </div>
  </div>
  <div class="metric blue">
    <div class="label">Last appraisal</div>
    <div class="value"><?= $myAppraisal ? money($myAppraisal['total_score']) : '&mdash;' ?></div>
    <div class="note"><?= $myAppraisal ? e($myAppraisal['rating']) . ' &middot; ' . money($myAppraisal['bonus_percentage']) . '% bonus' : 'Awaiting first review' ?></div>
  </div>
  <div class="metric gold">
    <div class="label">Annual leave used</div>
    <div class="value"><?= $leaveTaken ?> / <?= $annualDays ?></div>
    <div class="note"><?= max(0, $annualDays - $leaveTaken) ?> days still available</div>
  </div>
  <div class="metric">
    <div class="label">Days attended this month</div>
    <div class="value"><?= $daysThisMonth ?></div>
    <div class="note">Out of <?= (int)setting('working_days', 22) ?> working days</div>
  </div>
</div>
<?php elseif (!has_role(['admin','hr','manager'])): ?>
  <div class="panel empty">
    <h2>No staff record linked</h2>
    <p>This account is not attached to an employee record, so there is nothing personal to show. Ask HR to link it.</p>
  </div>
<?php endif; ?>

<?php if (!empty($trend)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var el = document.getElementById('payrollChart');
  if (!el || typeof Chart === 'undefined') return;
  new Chart(el, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($t) => substr(month_name((int)$t['period_month']), 0, 3) . ' ' . $t['period_year'], $trend)) ?>,
      datasets: [
        { label: 'Gross pay', data: <?= json_encode(array_map(fn($t) => (float)$t['gross'], $trend)) ?>, backgroundColor: '#f8923c' },
        { label: 'Performance bonus', data: <?= json_encode(array_map(fn($t) => (float)$t['bonus'], $trend)) ?>, backgroundColor: '#1f2937' }
      ]
    },
    options: {
      responsive: true,
      scales: { x: { stacked: false, grid: { display: false } }, y: { beginAtZero: true } },
      plugins: { legend: { position: 'bottom' } }
    }
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
