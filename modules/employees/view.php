<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin', 'hr', 'manager']);

$id  = (int)($_GET['id'] ?? 0);
$cur = setting('currency', 'USD');

$stmt = $pdo->prepare(
    "SELECT e.*, d.dept_name, p.title AS position_title, p.grade,
            m.first_name AS mgr_first, m.last_name AS mgr_last
     FROM employees e
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN positions   p ON p.position_id   = e.position_id
     LEFT JOIN employees   m ON m.employee_id   = e.manager_id
     WHERE e.employee_id = ?"
);
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) { set_flash('error', 'Employee not found.'); redirect('modules/employees/index.php'); }

// Deleting is restricted to administrators and is written to the audit trail.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    require_role(['admin']);
    $pdo->prepare('DELETE FROM employees WHERE employee_id = ?')->execute([$id]);
    log_action('DELETE', 'Employees', $id, 'Deleted ' . $emp['first_name'] . ' ' . $emp['last_name']);
    set_flash('success', 'Employee record deleted along with its linked records.');
    redirect('modules/employees/index.php');
}

$appraisals = $pdo->prepare(
    "SELECT a.*, p.name AS period_name FROM performance_appraisals a
     JOIN appraisal_periods p ON p.period_id = a.period_id
     WHERE a.employee_id = ? ORDER BY p.end_date DESC"
);
$appraisals->execute([$id]);
$appraisals = $appraisals->fetchAll();

$payslips = $pdo->prepare(
    "SELECT s.*, p.period_month, p.period_year FROM payslips s
     JOIN payroll_periods p ON p.payroll_period_id = s.payroll_period_id
     WHERE s.employee_id = ? ORDER BY p.period_year DESC, p.period_month DESC LIMIT 6"
);
$payslips->execute([$id]);
$payslips = $payslips->fetchAll();

$leaves = $pdo->prepare(
    "SELECT l.*, t.type_name FROM leave_applications l
     JOIN leave_types t ON t.leave_type_id = l.leave_type_id
     WHERE l.employee_id = ? ORDER BY l.applied_on DESC LIMIT 6"
);
$leaves->execute([$id]);
$leaves = $leaves->fetchAll();

$gross     = $emp['basic_salary'] + $emp['housing_allowance'] + $emp['transport_allowance'];
$pageTitle = $emp['first_name'] . ' ' . $emp['last_name'];
include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?> &middot; <?= e($emp['emp_number']) ?></h2>
    <?= status_badge($emp['status']) ?>
    <div class="spacer"></div>
    <?php if (has_role(['admin','hr'])): ?>
      <a class="btn secondary small" href="<?= url('modules/employees/form.php?id=' . $id) ?>">Edit</a>
      <a class="btn secondary small" href="<?= url('modules/performance/appraise.php?employee_id=' . $id) ?>">Appraise</a>
    <?php endif; ?>
    <?php if (has_role(['admin'])): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button class="btn danger small" data-confirm="Delete this employee and all their payslips, appraisals and leave records? This cannot be undone.">Delete</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="panel-body">
    <div class="grid-3">
      <div><span class="hint">Department</span><br><?= e($emp['dept_name'] ?? 'Not assigned') ?></div>
      <div><span class="hint">Position</span><br><?= e($emp['position_title'] ?? 'Not assigned') ?><?= $emp['grade'] ? ' (grade ' . e($emp['grade']) . ')' : '' ?></div>
      <div><span class="hint">Reports to</span><br><?= $emp['mgr_first'] ? e($emp['mgr_first'] . ' ' . $emp['mgr_last']) : 'No line manager' ?></div>
      <div><span class="hint">Hired</span><br><?= fdate($emp['hire_date']) ?></div>
      <div><span class="hint">Employment type</span><br><?= e($emp['employment_type']) ?></div>
      <div><span class="hint">Email</span><br><?= e($emp['email'] ?: '—') ?></div>
      <div><span class="hint">Phone</span><br><?= e($emp['phone'] ?: '—') ?></div>
      <div><span class="hint">NSSA number</span><br><?= e($emp['nssa_number'] ?: '—') ?></div>
      <div><span class="hint">Tax number</span><br><?= e($emp['tax_number'] ?: '—') ?></div>
      <div><span class="hint">Bank</span><br><?= e($emp['bank_name'] ?: '—') ?> <?= e($emp['bank_account']) ?></div>
      <div><span class="hint">Basic salary</span><br><?= e($cur) ?> <?= money($emp['basic_salary']) ?></div>
      <div><span class="hint">Gross (basic + allowances)</span><br><strong><?= e($cur) ?> <?= money($gross) ?></strong></div>
    </div>
  </div>
</section>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Appraisal history</h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Period</th><th class="num">Score</th><th>Rating</th><th class="num">Bonus</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$appraisals): ?><tr><td colspan="5" class="muted">Not yet appraised.</td></tr><?php endif; ?>
        <?php foreach ($appraisals as $a): ?>
          <tr>
            <td><a href="<?= url('modules/performance/appraise.php?id=' . $a['appraisal_id']) ?>"><?= e($a['period_name']) ?></a></td>
            <td class="num"><?= money($a['total_score']) ?></td>
            <td><?= e($a['rating'] ?? '—') ?></td>
            <td class="num"><?= money($a['bonus_percentage']) ?>%</td>
            <td><?= status_badge($a['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Recent payslips</h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Period</th><th class="num">Gross</th><th class="num">Bonus</th><th class="num">Net</th><th></th></tr></thead>
        <tbody>
        <?php if (!$payslips): ?><tr><td colspan="5" class="muted">No payslips issued.</td></tr><?php endif; ?>
        <?php foreach ($payslips as $s): ?>
          <tr>
            <td class="nowrap"><?= e(month_name((int)$s['period_month'])) ?> <?= (int)$s['period_year'] ?></td>
            <td class="num"><?= money($s['gross_pay']) ?></td>
            <td class="num"><?= money($s['performance_bonus']) ?></td>
            <td class="num strong"><?= money($s['net_pay']) ?></td>
            <td class="right"><a class="btn secondary small" href="<?= url('modules/payroll/payslip.php?id=' . $s['payslip_id']) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>Recent leave</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Type</th><th>From</th><th>To</th><th class="num">Days</th><th>Status</th><th>Reason</th></tr></thead>
      <tbody>
      <?php if (!$leaves): ?><tr><td colspan="6" class="muted">No leave applications.</td></tr><?php endif; ?>
      <?php foreach ($leaves as $l): ?>
        <tr>
          <td><?= e($l['type_name']) ?></td>
          <td class="nowrap"><?= fdate($l['start_date']) ?></td>
          <td class="nowrap"><?= fdate($l['end_date']) ?></td>
          <td class="num"><?= (int)$l['days_requested'] ?></td>
          <td><?= status_badge($l['status']) ?></td>
          <td class="small muted"><?= e($l['reason']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
