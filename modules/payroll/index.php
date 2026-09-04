<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin','hr']);

$pageTitle = 'Payroll runs';
$cur       = setting('currency', 'USD');

/**
 * Builds every payslip for a period. Safe to run again: existing
 * payslips for the period are overwritten with the recalculated figures.
 */
function process_payroll(PDO $pdo, array $period): array {
    $workingDays  = (float)setting('working_days', 22);
    $workingHours = (float)setting('working_hours', 8);
    $otMultiplier = (float)setting('overtime_rate', 1.5);
    $month = (int)$period['period_month'];
    $year  = (int)$period['period_year'];

    $employees = $pdo->query("SELECT * FROM employees WHERE status = 'Active' ORDER BY emp_number")->fetchAll();

    $otStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(overtime_hours),0) AS ot, COUNT(*) AS days
         FROM attendance WHERE employee_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ?
           AND status IN ("Present","Late","Half Day")'
    );
    $unpaidStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(l.days_requested),0)
         FROM leave_applications l JOIN leave_types t ON t.leave_type_id = l.leave_type_id
         WHERE l.employee_id = ? AND l.status = "Approved" AND t.is_paid = 0
           AND MONTH(l.start_date) = ? AND YEAR(l.start_date) = ?'
    );

    $save = $pdo->prepare(
        'INSERT INTO payslips
         (payroll_period_id, employee_id, appraisal_id, basic_salary, housing_allowance, transport_allowance,
          overtime_pay, performance_bonus, bonus_percentage, gross_pay, paye, aids_levy, nssa,
          unpaid_leave_deduction, total_deductions, net_pay, days_worked)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
          appraisal_id = VALUES(appraisal_id), basic_salary = VALUES(basic_salary),
          housing_allowance = VALUES(housing_allowance), transport_allowance = VALUES(transport_allowance),
          overtime_pay = VALUES(overtime_pay), performance_bonus = VALUES(performance_bonus),
          bonus_percentage = VALUES(bonus_percentage), gross_pay = VALUES(gross_pay),
          paye = VALUES(paye), aids_levy = VALUES(aids_levy), nssa = VALUES(nssa),
          unpaid_leave_deduction = VALUES(unpaid_leave_deduction), total_deductions = VALUES(total_deductions),
          net_pay = VALUES(net_pay), days_worked = VALUES(days_worked)'
    );

    $count = 0; $totalNet = 0.0; $totalBonus = 0.0;

    foreach ($employees as $emp) {
        $basic = (float)$emp['basic_salary'];

        $otStmt->execute([$emp['employee_id'], $month, $year]);
        $att = $otStmt->fetch();
        $hourly     = $workingDays * $workingHours > 0 ? $basic / ($workingDays * $workingHours) : 0;
        $overtimePay= round((float)$att['ot'] * $hourly * $otMultiplier, 2);

        $unpaidStmt->execute([$emp['employee_id'], $month, $year]);
        $unpaidDays  = (float)$unpaidStmt->fetchColumn();
        $dailyRate   = $workingDays > 0 ? $basic / $workingDays : 0;
        $unpaidDeduct= round($unpaidDays * $dailyRate, 2);

        // The performance link: an approved appraisal sets the bonus.
        $appraisal = latest_approved_appraisal((int)$emp['employee_id']);
        $bonusPct  = $appraisal ? (float)$appraisal['bonus_percentage'] : 0.0;
        $bonus     = round($basic * $bonusPct / 100, 2);

        $gross = round($basic + (float)$emp['housing_allowance'] + (float)$emp['transport_allowance']
                       + $overtimePay + $bonus, 2);

        $nssa    = calculate_nssa($gross);
        $taxable = max(0, $gross - $nssa);          // NSSA is deducted before PAYE
        $tax     = calculate_paye($taxable);

        $deductions = round($tax['paye'] + $tax['aids_levy'] + $nssa + $unpaidDeduct, 2);
        $net        = round($gross - $deductions, 2);

        $save->execute([
            $period['payroll_period_id'], $emp['employee_id'],
            $appraisal['appraisal_id'] ?? null,
            $basic, $emp['housing_allowance'], $emp['transport_allowance'],
            $overtimePay, $bonus, $bonusPct, $gross,
            $tax['paye'], $tax['aids_levy'], $nssa, $unpaidDeduct, $deductions, $net,
            (int)$att['days'],
        ]);

        $count++; $totalNet += $net; $totalBonus += $bonus;
    }

    return ['count' => $count, 'net' => $totalNet, 'bonus' => $totalBonus];
}

/* ------------------------------------------------------------------
   Actions
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $m = (int)$_POST['period_month'];
        $y = (int)$_POST['period_year'];
        $d = $_POST['pay_date'];
        try {
            $pdo->prepare('INSERT INTO payroll_periods (period_month, period_year, pay_date) VALUES (?,?,?)')
                ->execute([$m, $y, $d]);
            log_action('CREATE', 'Payroll', (int)$pdo->lastInsertId(), 'Opened payroll for ' . month_name($m) . " $y");
            set_flash('success', 'Payroll period opened for ' . month_name($m) . " $y.");
        } catch (PDOException $ex) {
            set_flash('error', 'A payroll period already exists for ' . month_name($m) . " $y.");
        }
    }

    if ($action === 'process') {
        $pid  = (int)$_POST['payroll_period_id'];
        $stmt = $pdo->prepare('SELECT * FROM payroll_periods WHERE payroll_period_id = ?');
        $stmt->execute([$pid]);
        $period = $stmt->fetch();

        if (!$period) {
            set_flash('error', 'Payroll period not found.');
        } elseif ($period['status'] === 'Paid') {
            set_flash('error', 'That run is already marked as paid and cannot be reprocessed.');
        } else {
            $pdo->beginTransaction();
            try {
                $result = process_payroll($pdo, $period);
                $pdo->prepare("UPDATE payroll_periods SET status = 'Processed', processed_by = ?, processed_on = NOW()
                               WHERE payroll_period_id = ?")
                    ->execute([current_user()['user_id'], $pid]);
                $pdo->commit();
            } catch (PDOException $ex) {
                $pdo->rollBack();
                set_flash('error', 'Payroll failed and nothing was saved: ' . e($ex->getMessage()));
                redirect('modules/payroll/index.php');
            }

            log_action('PROCESS', 'Payroll', $pid,
                sprintf('Processed %s %d: %d payslips, net %s, bonuses %s',
                    month_name((int)$period['period_month']), $period['period_year'],
                    $result['count'], money($result['net']), money($result['bonus'])));

            notify_roles(['hr','admin'], 'Payroll processed',
                month_name((int)$period['period_month']) . ' ' . $period['period_year'] . ': '
                . $result['count'] . ' payslips totalling ' . setting('currency','USD') . ' ' . money($result['net']),
                'modules/payroll/payslips.php?period=' . $pid);

            set_flash('success', sprintf(
                'Processed %d payslips. Net pay %s %s, of which %s %s is performance bonus.',
                $result['count'], setting('currency','USD'), money($result['net']),
                setting('currency','USD'), money($result['bonus'])));
        }
    }

    if ($action === 'mark_paid') {
        $pid = (int)$_POST['payroll_period_id'];
        $pdo->prepare("UPDATE payroll_periods SET status = 'Paid' WHERE payroll_period_id = ? AND status = 'Processed'")
            ->execute([$pid]);
        log_action('UPDATE', 'Payroll', $pid, 'Marked payroll as paid');

        // Tell each employee their payslip is ready.
        $stmt = $pdo->prepare(
            'SELECT s.payslip_id, s.net_pay, u.user_id, p.period_month, p.period_year
             FROM payslips s
             JOIN payroll_periods p ON p.payroll_period_id = s.payroll_period_id
             JOIN users u ON u.employee_id = s.employee_id
             WHERE s.payroll_period_id = ?'
        );
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll() as $row) {
            notify((int)$row['user_id'], 'Payslip available',
                month_name((int)$row['period_month']) . ' ' . $row['period_year'] . ' net pay: '
                . setting('currency','USD') . ' ' . money($row['net_pay']),
                'modules/payroll/payslip.php?id=' . $row['payslip_id']);
        }
        set_flash('success', 'Run marked as paid and employees notified.');
    }

    if ($action === 'delete') {
        $pid = (int)$_POST['payroll_period_id'];
        $pdo->prepare("DELETE FROM payroll_periods WHERE payroll_period_id = ? AND status <> 'Paid'")->execute([$pid]);
        log_action('DELETE', 'Payroll', $pid, 'Deleted payroll period and its payslips');
        set_flash('success', 'Payroll period deleted.');
    }
    redirect('modules/payroll/index.php');
}

$runs = $pdo->query(
    'SELECT p.*, u.username, COUNT(s.payslip_id) AS slips,
            COALESCE(SUM(s.gross_pay),0) AS gross, COALESCE(SUM(s.net_pay),0) AS net,
            COALESCE(SUM(s.paye + s.aids_levy),0) AS tax, COALESCE(SUM(s.nssa),0) AS nssa,
            COALESCE(SUM(s.performance_bonus),0) AS bonus
     FROM payroll_periods p
     LEFT JOIN payslips s ON s.payroll_period_id = p.payroll_period_id
     LEFT JOIN users u ON u.user_id = p.processed_by
     GROUP BY p.payroll_period_id
     ORDER BY p.period_year DESC, p.period_month DESC'
)->fetchAll();

$pendingAppraisals = (int)$pdo->query(
    "SELECT COUNT(*) FROM performance_appraisals WHERE status = 'Submitted'"
)->fetchColumn();

include APP_ROOT . '/includes/header.php';
?>

<?php if ($pendingAppraisals): ?>
  <div class="alert info">
    <?= $pendingAppraisals ?> appraisal<?= $pendingAppraisals === 1 ? ' is' : 's are' ?> submitted but not approved.
    Only approved appraisals release a bonus into payroll.
    <a href="<?= url('modules/performance/index.php') ?>">Review them first</a>.
  </div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>Open a payroll period</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="filters">
      <div class="field">
        <label for="period_month">Month</label>
        <select id="period_month" name="period_month">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m === (int)date('n') ? 'selected' : '' ?>><?= month_name($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field">
        <label for="period_year">Year</label>
        <input type="number" id="period_year" name="period_year" value="<?= date('Y') ?>" min="2020" max="2100">
      </div>
      <div class="field">
        <label for="pay_date">Pay date</label>
        <input type="date" id="pay_date" name="pay_date" value="<?= date('Y-m-t') ?>" required>
      </div>
      <div class="field"><label>&nbsp;</label><button class="btn">Open period</button></div>
    </div>
  </form>
</section>

<section class="panel">
  <div class="panel-head">
    <h2>Runs</h2>
    <div class="spacer"></div>
    <span class="small muted">Amounts in <?= e($cur) ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Period</th><th>Pay date</th><th class="num">Payslips</th><th class="num">Gross</th>
            <th class="num">Bonus</th><th class="num">PAYE + levy</th><th class="num">NSSA</th>
            <th class="num">Net</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$runs): ?>
        <tr><td colspan="10" class="muted">No payroll periods yet. Open one above to get started.</td></tr>
      <?php endif; ?>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td class="strong nowrap"><?= e(month_name((int)$r['period_month'])) ?> <?= (int)$r['period_year'] ?>
              <?php if ($r['username']): ?><br><span class="small muted">by <?= e($r['username']) ?></span><?php endif; ?></td>
          <td class="nowrap"><?= fdate($r['pay_date']) ?></td>
          <td class="num"><?= (int)$r['slips'] ?></td>
          <td class="num"><?= money($r['gross']) ?></td>
          <td class="num"><?= money($r['bonus']) ?></td>
          <td class="num"><?= money($r['tax']) ?></td>
          <td class="num"><?= money($r['nssa']) ?></td>
          <td class="num strong"><?= money($r['net']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td>
            <div class="row-actions">
              <?php if ($r['slips']): ?>
                <a class="btn secondary small" href="<?= url('modules/payroll/payslips.php?period=' . $r['payroll_period_id']) ?>">Payslips</a>
              <?php endif; ?>
              <?php if ($r['status'] !== 'Paid'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="process">
                  <input type="hidden" name="payroll_period_id" value="<?= $r['payroll_period_id'] ?>">
                  <button class="btn small"
                    data-confirm="Calculate pay for every active employee in this period? Existing payslips for the period will be recalculated.">
                    <?= $r['slips'] ? 'Reprocess' : 'Process' ?>
                  </button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'Processed'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="payroll_period_id" value="<?= $r['payroll_period_id'] ?>">
                  <button class="btn secondary small" data-confirm="Mark as paid and notify employees? The run is then locked.">Mark paid</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] !== 'Paid' && has_role(['admin'])): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="payroll_period_id" value="<?= $r['payroll_period_id'] ?>">
                  <button class="btn danger small" data-confirm="Delete this period and all its payslips?">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>How each payslip is worked out</h2></div>
  <div class="panel-body">
    <ol class="small" style="margin:0;padding-left:1.2rem;line-height:1.8">
      <li>Overtime pay = overtime hours this month &times; hourly rate &times; <?= e(setting('overtime_rate', '1.5')) ?></li>
      <li>Performance bonus = basic salary &times; the bonus percentage from the employee's latest <strong>approved</strong> appraisal</li>
      <li>Gross = basic + housing + transport + overtime + bonus</li>
      <li>NSSA = <?= money((float)setting('nssa_rate', 0.045) * 100) ?>% of gross, capped at <?= e($cur) ?> <?= money(setting('nssa_ceiling', 700)) ?> of insurable earnings</li>
      <li>PAYE comes from the tax band table applied to gross less NSSA, plus a <?= money((float)setting('aids_levy_rate', 0.03) * 100) ?>% AIDS levy on the tax</li>
      <li>Net = gross &minus; (PAYE + levy + NSSA + unpaid leave)</li>
    </ol>
    <p class="hint" style="margin-top:.75rem">
      Rates and bands live in Settings, so they can be updated when ZIMRA publishes new tables.
    </p>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
