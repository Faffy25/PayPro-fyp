<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin','hr','manager']);

$pageTitle = 'Reports';
$cur       = setting('currency', 'USD');
$report    = $_GET['report'] ?? 'payroll';
$periodId  = (int)($_GET['period'] ?? 0);
$month     = (int)($_GET['month'] ?? date('n'));
$year      = (int)($_GET['year']  ?? date('Y'));

$payrollPeriods = $pdo->query('SELECT * FROM payroll_periods ORDER BY period_year DESC, period_month DESC')->fetchAll();
if (!$periodId && $payrollPeriods) $periodId = (int)$payrollPeriods[0]['payroll_period_id'];

$apprPeriods = $pdo->query('SELECT * FROM appraisal_periods ORDER BY end_date DESC')->fetchAll();
$apprPeriod  = (int)($_GET['appraisal_period'] ?? ($apprPeriods[0]['period_id'] ?? 0));

/* ---------------- Report queries ---------------- */
$rows = [];

if ($report === 'payroll') {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(d.dept_name,'Unassigned') AS dept_name, COUNT(*) AS staff,
                SUM(s.basic_salary) AS basic, SUM(s.performance_bonus) AS bonus,
                SUM(s.gross_pay) AS gross, SUM(s.paye + s.aids_levy) AS tax,
                SUM(s.nssa) AS nssa, SUM(s.net_pay) AS net
         FROM payslips s
         JOIN employees e ON e.employee_id = s.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         WHERE s.payroll_period_id = ?
         GROUP BY d.department_id ORDER BY net DESC"
    );
    $stmt->execute([$periodId]);
    $rows = $stmt->fetchAll();
}

if ($report === 'performance') {
    $stmt = $pdo->prepare(
        "SELECT a.rating, COUNT(*) AS staff, AVG(a.total_score) AS avg_score,
                AVG(a.bonus_percentage) AS avg_bonus
         FROM performance_appraisals a
         WHERE a.period_id = ? AND a.rating IS NOT NULL
         GROUP BY a.rating ORDER BY avg_score DESC"
    );
    $stmt->execute([$apprPeriod]);
    $rows = $stmt->fetchAll();

    $detail = $pdo->prepare(
        "SELECT e.emp_number, e.first_name, e.last_name, d.dept_name,
                a.total_score, a.rating, a.bonus_percentage, a.status
         FROM performance_appraisals a
         JOIN employees e ON e.employee_id = a.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         WHERE a.period_id = ? ORDER BY a.total_score DESC"
    );
    $detail->execute([$apprPeriod]);
    $detail = $detail->fetchAll();
}

if ($report === 'attendance') {
    $stmt = $pdo->prepare(
        "SELECT e.emp_number, e.first_name, e.last_name, COALESCE(d.dept_name,'—') AS dept_name,
                SUM(a.status = 'Present') AS present, SUM(a.status = 'Late') AS late,
                SUM(a.status = 'Absent') AS absent, SUM(a.status = 'On Leave') AS on_leave,
                COALESCE(SUM(a.hours_worked),0) AS hours, COALESCE(SUM(a.overtime_hours),0) AS overtime
         FROM employees e
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN attendance a ON a.employee_id = e.employee_id
              AND MONTH(a.work_date) = ? AND YEAR(a.work_date) = ?
         WHERE e.status = 'Active'
         GROUP BY e.employee_id ORDER BY e.emp_number"
    );
    $stmt->execute([$month, $year]);
    $rows = $stmt->fetchAll();
}

if ($report === 'statutory') {
    $stmt = $pdo->prepare(
        "SELECT e.emp_number, e.first_name, e.last_name, e.national_id, e.tax_number, e.nssa_number,
                s.gross_pay, s.paye, s.aids_levy, s.nssa
         FROM payslips s JOIN employees e ON e.employee_id = s.employee_id
         WHERE s.payroll_period_id = ? ORDER BY e.emp_number"
    );
    $stmt->execute([$periodId]);
    $rows = $stmt->fetchAll();
}

$qs = http_build_query(['report' => $report, 'period' => $periodId, 'month' => $month, 'year' => $year, 'appraisal_period' => $apprPeriod]);

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2>Choose a report</h2>
    <div class="spacer"></div>
    <a class="btn secondary small" href="<?= url('modules/reports/export.php?' . $qs) ?>">Download CSV</a>
    <button class="btn secondary small no-print" onclick="window.print()">Print</button>
  </div>
  <form method="get" class="panel-body filters">
    <div class="field">
      <label for="report">Report</label>
      <select id="report" name="report">
        <option value="payroll"     <?= $report === 'payroll' ? 'selected' : '' ?>>Payroll cost by department</option>
        <option value="performance" <?= $report === 'performance' ? 'selected' : '' ?>>Performance distribution</option>
        <option value="attendance"  <?= $report === 'attendance' ? 'selected' : '' ?>>Monthly attendance</option>
        <option value="statutory"   <?= $report === 'statutory' ? 'selected' : '' ?>>Statutory return (PAYE and NSSA)</option>
      </select>
    </div>

    <?php if (in_array($report, ['payroll','statutory'], true)): ?>
      <div class="field">
        <label for="period">Payroll period</label>
        <select id="period" name="period">
          <?php foreach ($payrollPeriods as $p): ?>
            <option value="<?= $p['payroll_period_id'] ?>" <?= $periodId === (int)$p['payroll_period_id'] ? 'selected' : '' ?>>
              <?= e(month_name((int)$p['period_month'])) ?> <?= (int)$p['period_year'] ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($report === 'performance'): ?>
      <div class="field">
        <label for="appraisal_period">Review period</label>
        <select id="appraisal_period" name="appraisal_period">
          <?php foreach ($apprPeriods as $p): ?>
            <option value="<?= $p['period_id'] ?>" <?= $apprPeriod === (int)$p['period_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($report === 'attendance'): ?>
      <div class="field">
        <label for="month">Month</label>
        <select id="month" name="month">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= month_name($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field"><label for="year">Year</label>
        <input type="number" id="year" name="year" value="<?= $year ?>" min="2020" max="2100"></div>
    <?php endif; ?>

    <div class="field"><label>&nbsp;</label><button class="btn">Run report</button></div>
  </form>
</section>

<?php if ($report === 'payroll'): ?>
<section class="panel">
  <div class="panel-head"><h2>Payroll cost by department</h2>
    <div class="spacer"></div><span class="small muted">Amounts in <?= e($cur) ?></span></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Department</th><th class="num">Staff</th><th class="num">Basic</th><th class="num">Bonus</th>
                 <th class="num">Gross</th><th class="num">PAYE + levy</th><th class="num">NSSA</th><th class="num">Net</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">No payslips in this period.</td></tr><?php endif; ?>
      <?php $t = array_fill_keys(['staff','basic','bonus','gross','tax','nssa','net'], 0);
            foreach ($rows as $r): foreach ($t as $k => $_) $t[$k] += $r[$k]; ?>
        <tr>
          <td class="strong"><?= e($r['dept_name']) ?></td>
          <td class="num"><?= (int)$r['staff'] ?></td>
          <td class="num"><?= money($r['basic']) ?></td>
          <td class="num"><?= money($r['bonus']) ?></td>
          <td class="num"><?= money($r['gross']) ?></td>
          <td class="num"><?= money($r['tax']) ?></td>
          <td class="num"><?= money($r['nssa']) ?></td>
          <td class="num strong"><?= money($r['net']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr><td>Total</td><td class="num"><?= (int)$t['staff'] ?></td><td class="num"><?= money($t['basic']) ?></td>
        <td class="num"><?= money($t['bonus']) ?></td><td class="num"><?= money($t['gross']) ?></td>
        <td class="num"><?= money($t['tax']) ?></td><td class="num"><?= money($t['nssa']) ?></td>
        <td class="num"><?= money($t['net']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($report === 'performance'): ?>
<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Rating distribution</h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Rating</th><th class="num">Staff</th><th class="num">Average score</th><th class="num">Average bonus</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">No rated appraisals in this period.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="strong"><?= e($r['rating']) ?></td>
            <td class="num"><?= (int)$r['staff'] ?></td>
            <td class="num"><?= money($r['avg_score']) ?></td>
            <td class="num"><?= money($r['avg_bonus']) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Individual scores</h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Employee</th><th>Department</th><th class="num">Score</th><th class="num">Bonus</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($detail as $d): ?>
          <tr>
            <td><?= e($d['first_name'] . ' ' . $d['last_name']) ?></td>
            <td><?= e($d['dept_name'] ?? '—') ?></td>
            <td class="num"><?= money($d['total_score']) ?></td>
            <td class="num"><?= money($d['bonus_percentage']) ?>%</td>
            <td><?= status_badge($d['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$detail): ?><tr><td colspan="5" class="muted">Nothing recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php endif; ?>

<?php if ($report === 'attendance'): ?>
<section class="panel">
  <div class="panel-head"><h2><?= e(month_name($month)) ?> <?= $year ?> attendance</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Employee</th><th>Department</th><th class="num">Present</th><th class="num">Late</th>
                 <th class="num">Absent</th><th class="num">On leave</th><th class="num">Hours</th><th class="num">Overtime</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="strong"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
              <span class="small muted"><?= e($r['emp_number']) ?></span></td>
          <td><?= e($r['dept_name']) ?></td>
          <td class="num"><?= (int)$r['present'] ?></td>
          <td class="num"><?= (int)$r['late'] ?></td>
          <td class="num"><?= (int)$r['absent'] ?></td>
          <td class="num"><?= (int)$r['on_leave'] ?></td>
          <td class="num"><?= money($r['hours']) ?></td>
          <td class="num"><?= money($r['overtime']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($report === 'statutory'): ?>
<section class="panel">
  <div class="panel-head"><h2>Statutory return</h2>
    <div class="spacer"></div><span class="small muted">Per employee, amounts in <?= e($cur) ?></span></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Employee</th><th>National ID</th><th>Tax number</th><th>NSSA number</th>
                 <th class="num">Gross</th><th class="num">PAYE</th><th class="num">AIDS levy</th><th class="num">NSSA</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted">No payslips in this period.</td></tr><?php endif; ?>
      <?php $tp = 0; $tl = 0; $tn = 0;
            foreach ($rows as $r): $tp += $r['paye']; $tl += $r['aids_levy']; $tn += $r['nssa']; ?>
        <tr>
          <td class="strong"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
              <span class="small muted"><?= e($r['emp_number']) ?></span></td>
          <td class="small"><?= e($r['national_id'] ?: '—') ?></td>
          <td class="small"><?= e($r['tax_number'] ?: '—') ?></td>
          <td class="small"><?= e($r['nssa_number'] ?: '—') ?></td>
          <td class="num"><?= money($r['gross_pay']) ?></td>
          <td class="num"><?= money($r['paye']) ?></td>
          <td class="num"><?= money($r['aids_levy']) ?></td>
          <td class="num"><?= money($r['nssa']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr><td colspan="5">Remittance total</td><td class="num"><?= money($tp) ?></td>
        <td class="num"><?= money($tl) ?></td><td class="num"><?= money($tn) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
