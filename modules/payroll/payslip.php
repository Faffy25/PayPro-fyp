<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$cur = setting('currency', 'USD');

$stmt = $pdo->prepare(
    "SELECT s.*, e.emp_number, e.first_name, e.last_name, e.national_id, e.nssa_number, e.tax_number,
            e.bank_name, e.bank_account, e.employee_id,
            d.dept_name, po.title AS position_title,
            p.period_month, p.period_year, p.pay_date, p.status AS run_status,
            a.total_score, a.rating, ap.name AS period_name
     FROM payslips s
     JOIN employees e ON e.employee_id = s.employee_id
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN positions  po ON po.position_id  = e.position_id
     JOIN payroll_periods p ON p.payroll_period_id = s.payroll_period_id
     LEFT JOIN performance_appraisals a ON a.appraisal_id = s.appraisal_id
     LEFT JOIN appraisal_periods ap ON ap.period_id = a.period_id
     WHERE s.payslip_id = ?"
);
$stmt->execute([$id]);
$slip = $stmt->fetch();

if (!$slip) { set_flash('error', 'Payslip not found.'); redirect('modules/payroll/payslips.php'); }

// Employees may only open their own payslip.
if (!has_role(['admin','hr']) && (int)$slip['employee_id'] !== (int)(current_user()['employee_id'] ?? 0)) {
    require_role(['admin','hr']);
}

$earnings = [
    'Basic salary'        => $slip['basic_salary'],
    'Housing allowance'   => $slip['housing_allowance'],
    'Transport allowance' => $slip['transport_allowance'],
    'Overtime'            => $slip['overtime_pay'],
];
$bonusLabel = 'Performance bonus' . ($slip['bonus_percentage'] > 0 ? ' (' . money($slip['bonus_percentage']) . '% of basic)' : '');
$earnings[$bonusLabel] = $slip['performance_bonus'];

$deductions = [
    'PAYE'                 => $slip['paye'],
    'AIDS levy'            => $slip['aids_levy'],
    'NSSA contribution'    => $slip['nssa'],
    'Unpaid leave'         => $slip['unpaid_leave_deduction'],
];

$pageTitle = 'Payslip · ' . month_name((int)$slip['period_month']) . ' ' . $slip['period_year'];
include APP_ROOT . '/includes/header.php';
?>

<div class="no-print" style="margin-bottom:1rem;display:flex;gap:.5rem">
  <button class="btn" onclick="window.print()">Print payslip</button>
  <a class="btn secondary" href="<?= url('modules/payroll/payslips.php') ?>">Back to payslips</a>
</div>

<article class="payslip">
  <header>
    <div>
      <div class="co"><?= e(setting('company_name', 'Company')) ?></div>
      <div class="small muted"><?= e(setting('company_address', '')) ?></div>
    </div>
    <div class="right">
      <div class="strong">Payslip</div>
      <div class="small muted">
        <?= e(month_name((int)$slip['period_month'])) ?> <?= (int)$slip['period_year'] ?><br>
        Pay date <?= fdate($slip['pay_date']) ?><br>
        <?= e($slip['run_status']) ?>
      </div>
    </div>
  </header>

  <div class="meta">
    <div><span>Employee</span><?= e($slip['first_name'] . ' ' . $slip['last_name']) ?></div>
    <div><span>Number</span><?= e($slip['emp_number']) ?></div>
    <div><span>Department</span><?= e($slip['dept_name'] ?? '—') ?></div>
    <div><span>Position</span><?= e($slip['position_title'] ?? '—') ?></div>
    <div><span>National ID</span><?= e($slip['national_id'] ?: '—') ?></div>
    <div><span>NSSA number</span><?= e($slip['nssa_number'] ?: '—') ?></div>
    <div><span>Tax number</span><?= e($slip['tax_number'] ?: '—') ?></div>
    <div><span>Bank</span><?= e($slip['bank_name'] ?: '—') ?> <?= e($slip['bank_account']) ?></div>
  </div>

  <div class="grid-2">
    <div>
      <h3>Earnings</h3>
      <table class="data">
        <tbody>
        <?php foreach ($earnings as $label => $amount): ?>
          <tr><td><?= e($label) ?></td><td class="num"><?= money($amount) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Gross pay</td><td class="num"><?= money($slip['gross_pay']) ?></td></tr></tfoot>
      </table>
    </div>
    <div>
      <h3>Deductions</h3>
      <table class="data">
        <tbody>
        <?php foreach ($deductions as $label => $amount): ?>
          <tr><td><?= e($label) ?></td><td class="num"><?= money($amount) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total deductions</td><td class="num"><?= money($slip['total_deductions']) ?></td></tr></tfoot>
      </table>
    </div>
  </div>

  <?php if ($slip['appraisal_id']): ?>
    <p class="small muted" style="margin-top:1rem">
      The bonus above comes from the <?= e($slip['period_name']) ?> appraisal:
      <?= money($slip['total_score']) ?> out of 100, rated <?= e($slip['rating']) ?>.
    </p>
  <?php endif; ?>

  <div class="totals">
    <div class="netbox">
      <div class="small">Net pay &middot; <?= e($cur) ?></div>
      <div class="value"><?= money($slip['net_pay']) ?></div>
      <div class="small muted"><?= (int)$slip['days_worked'] ?> days attended</div>
    </div>
  </div>

  <p class="small muted" style="margin-top:1.5rem;border-top:1px solid var(--line);padding-top:.75rem">
    Computer generated payslip. Issued <?= fdate($slip['generated_on']) ?>. Keep it for your records.
  </p>
</article>

<?php include APP_ROOT . '/includes/footer.php'; ?>
