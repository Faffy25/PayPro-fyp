<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$pageTitle = has_role(['admin','hr']) ? 'Payslips' : 'My payslips';
$cur       = setting('currency', 'USD');
$periodId  = (int)($_GET['period'] ?? 0);

$sql = "SELECT s.*, e.emp_number, e.first_name, e.last_name, d.dept_name,
               p.period_month, p.period_year, p.status AS run_status
        FROM payslips s
        JOIN employees e ON e.employee_id = s.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        JOIN payroll_periods p ON p.payroll_period_id = s.payroll_period_id
        WHERE 1 = 1";
$args = [];

if (!has_role(['admin','hr'])) {
    // Employees and managers only see their own payslips.
    $sql .= ' AND s.employee_id = ?';
    $args[] = current_user()['employee_id'] ?? 0;
}
if ($periodId) { $sql .= ' AND s.payroll_period_id = ?'; $args[] = $periodId; }
$sql .= ' ORDER BY p.period_year DESC, p.period_month DESC, e.emp_number';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$slips = $stmt->fetchAll();

$periods = $pdo->query('SELECT * FROM payroll_periods ORDER BY period_year DESC, period_month DESC')->fetchAll();

$totals = ['gross' => 0, 'bonus' => 0, 'tax' => 0, 'nssa' => 0, 'net' => 0];
foreach ($slips as $s) {
    $totals['gross'] += $s['gross_pay'];
    $totals['bonus'] += $s['performance_bonus'];
    $totals['tax']   += $s['paye'] + $s['aids_levy'];
    $totals['nssa']  += $s['nssa'];
    $totals['net']   += $s['net_pay'];
}

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= count($slips) ?> payslip<?= count($slips) === 1 ? '' : 's' ?></h2>
    <div class="spacer"></div>
    <?php if (has_role(['admin','hr'])): ?>
      <input type="search" data-filter="#slipTable" placeholder="Search employee" style="max-width:200px">
      <form method="get" data-autosubmit>
        <select name="period">
          <option value="">All periods</option>
          <?php foreach ($periods as $p): ?>
            <option value="<?= $p['payroll_period_id'] ?>" <?= $periodId === (int)$p['payroll_period_id'] ? 'selected' : '' ?>>
              <?= e(month_name((int)$p['period_month'])) ?> <?= (int)$p['period_year'] ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table class="data" id="slipTable">
      <thead>
        <tr>
          <th>Period</th>
          <?php if (has_role(['admin','hr'])): ?><th>Employee</th><th>Department</th><?php endif; ?>
          <th class="num">Basic</th><th class="num">Bonus</th><th class="num">Gross</th>
          <th class="num">PAYE + levy</th><th class="num">NSSA</th><th class="num">Net</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$slips): ?>
        <tr><td colspan="10" class="muted">No payslips yet. They appear once a payroll run is processed.</td></tr>
      <?php endif; ?>
      <?php foreach ($slips as $s): ?>
        <tr>
          <td class="nowrap"><?= e(month_name((int)$s['period_month'])) ?> <?= (int)$s['period_year'] ?><br>
              <span class="small muted"><?= e($s['run_status']) ?></span></td>
          <?php if (has_role(['admin','hr'])): ?>
            <td class="strong"><?= e($s['first_name'] . ' ' . $s['last_name']) ?><br>
                <span class="small muted"><?= e($s['emp_number']) ?></span></td>
            <td><?= e($s['dept_name'] ?? '—') ?></td>
          <?php endif; ?>
          <td class="num"><?= money($s['basic_salary']) ?></td>
          <td class="num"><?= money($s['performance_bonus']) ?>
              <?php if ($s['bonus_percentage'] > 0): ?><br><span class="small muted"><?= money($s['bonus_percentage']) ?>%</span><?php endif; ?></td>
          <td class="num"><?= money($s['gross_pay']) ?></td>
          <td class="num"><?= money($s['paye'] + $s['aids_levy']) ?></td>
          <td class="num"><?= money($s['nssa']) ?></td>
          <td class="num strong"><?= money($s['net_pay']) ?></td>
          <td class="right"><a class="btn secondary small" href="<?= url('modules/payroll/payslip.php?id=' . $s['payslip_id']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($slips): ?>
      <tfoot>
        <tr>
          <td colspan="<?= has_role(['admin','hr']) ? 4 : 2 ?>">Totals (<?= e($cur) ?>)</td>
          <td class="num"><?= money($totals['bonus']) ?></td>
          <td class="num"><?= money($totals['gross']) ?></td>
          <td class="num"><?= money($totals['tax']) ?></td>
          <td class="num"><?= money($totals['nssa']) ?></td>
          <td class="num"><?= money($totals['net']) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
