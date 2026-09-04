<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$pageTitle = 'Appraisals';
$isOfficer = has_role(['admin','hr','manager']);
$periodId  = (int)($_GET['period_id'] ?? 0);

$periods = $pdo->query('SELECT * FROM appraisal_periods ORDER BY end_date DESC')->fetchAll();
if (!$periodId && $periods) $periodId = (int)$periods[0]['period_id'];

/* Employees who still have no appraisal in this period, so reviewers
   can see the gap rather than only what is already recorded. */
$sql = "SELECT e.employee_id, e.emp_number, e.first_name, e.last_name, d.dept_name,
               a.appraisal_id, a.total_score, a.rating, a.bonus_percentage, a.status
        FROM employees e
        LEFT JOIN departments d ON d.department_id = e.department_id
        LEFT JOIN performance_appraisals a
               ON a.employee_id = e.employee_id AND a.period_id = ?
        WHERE e.status = 'Active'";
$args = [$periodId];

if (!$isOfficer) {
    $sql .= ' AND e.employee_id = ?';
    $args[] = current_user()['employee_id'] ?? 0;
} elseif (has_role('manager')) {
    $sql .= ' AND (e.manager_id = ? OR e.employee_id = ?)';
    $args[] = current_user()['employee_id'];
    $args[] = current_user()['employee_id'];
}
$sql .= ' ORDER BY e.emp_number';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$done    = count(array_filter($rows, fn($r) => $r['status'] === 'Approved'));
$average = array_filter(array_map(fn($r) => $r['total_score'] !== null ? (float)$r['total_score'] : null, $rows));
$average = $average ? array_sum($average) / count($average) : 0;

include APP_ROOT . '/includes/header.php';
?>

<div class="metrics">
  <div class="metric">
    <div class="label">Staff in this review</div>
    <div class="value"><?= count($rows) ?></div>
  </div>
  <div class="metric blue">
    <div class="label">Approved appraisals</div>
    <div class="value"><?= $done ?></div>
    <div class="note"><?= count($rows) - $done ?> still outstanding</div>
  </div>
  <div class="metric gold">
    <div class="label">Average score</div>
    <div class="value"><?= money($average) ?></div>
    <div class="note">Weighted out of 100</div>
  </div>
</div>

<section class="panel">
  <div class="panel-head">
    <h2>Scores by employee</h2>
    <div class="spacer"></div>
    <form method="get" data-autosubmit>
      <select name="period_id">
        <?php foreach ($periods as $p): ?>
          <option value="<?= $p['period_id'] ?>" <?= $periodId === (int)$p['period_id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?> (<?= e($p['status']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Employee</th><th>Department</th><th class="num">Score</th><th>Rating</th>
            <th class="num">Bonus</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No employees to review in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="strong"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
              <span class="small muted"><?= e($r['emp_number']) ?></span></td>
          <td><?= e($r['dept_name'] ?? '—') ?></td>
          <td class="num"><?= $r['appraisal_id'] ? money($r['total_score']) : '—' ?></td>
          <td><?= e($r['rating'] ?? 'Not rated') ?></td>
          <td class="num"><?= $r['appraisal_id'] ? money($r['bonus_percentage']) . '%' : '—' ?></td>
          <td><?= $r['status'] ? status_badge($r['status']) : '<span class="badge neutral">Not started</span>' ?></td>
          <td class="right">
            <?php if ($isOfficer): ?>
              <a class="btn secondary small"
                 href="<?= url('modules/performance/appraise.php?employee_id=' . $r['employee_id'] . '&period_id=' . $periodId) ?>">
                <?= $r['appraisal_id'] ? 'Open' : 'Start appraisal' ?>
              </a>
            <?php elseif ($r['appraisal_id']): ?>
              <a class="btn secondary small" href="<?= url('modules/performance/appraise.php?id=' . $r['appraisal_id']) ?>">View</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
