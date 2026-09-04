<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin', 'hr', 'manager']);

$pageTitle = 'Employees';
$cur       = setting('currency', 'USD');

$dept   = $_GET['dept']   ?? '';
$status = $_GET['status'] ?? '';

$sql = "SELECT e.*, d.dept_name, p.title AS position_title
        FROM employees e
        LEFT JOIN departments d ON d.department_id = e.department_id
        LEFT JOIN positions   p ON p.position_id   = e.position_id
        WHERE 1 = 1";
$args = [];

// Managers only see the staff reporting to them plus themselves.
if (has_role('manager')) {
    $sql .= ' AND (e.manager_id = ? OR e.employee_id = ?)';
    $args[] = current_user()['employee_id'];
    $args[] = current_user()['employee_id'];
}
if ($dept !== '')   { $sql .= ' AND e.department_id = ?'; $args[] = $dept; }
if ($status !== '') { $sql .= ' AND e.status = ?';        $args[] = $status; }
$sql .= ' ORDER BY e.emp_number';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$employees = $stmt->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY dept_name')->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= count($employees) ?> employee<?= count($employees) === 1 ? '' : 's' ?></h2>
    <div class="spacer"></div>
    <?php if (has_role(['admin','hr'])): ?>
      <a class="btn" href="<?= url('modules/employees/form.php') ?>">Add employee</a>
    <?php endif; ?>
  </div>

  <div class="panel-body">
    <form class="filters" method="get" data-autosubmit>
      <div class="field">
        <label for="q">Search</label>
        <input type="search" id="q" data-filter="#empTable" placeholder="Name, number or email">
      </div>
      <div class="field">
        <label for="dept">Department</label>
        <select name="dept" id="dept">
          <option value="">All departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['department_id'] ?>" <?= $dept == $d['department_id'] ? 'selected' : '' ?>>
              <?= e($d['dept_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select name="status" id="status">
          <option value="">Any status</option>
          <?php foreach (['Active','Suspended','Terminated'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>&nbsp;</label>
        <a class="btn secondary" href="<?= url('modules/employees/index.php') ?>">Clear filters</a>
      </div>
      <div class="field"><label>&nbsp;</label><span class="hint" id="filterCount"></span></div>
    </form>
  </div>

  <div class="table-wrap">
    <table class="data" id="empTable">
      <thead>
        <tr>
          <th>Number</th><th>Name</th><th>Department</th><th>Position</th>
          <th>Hired</th><th class="num">Basic (<?= e($cur) ?>)</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$employees): ?>
        <tr><td colspan="8" class="muted">No employees match these filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td class="nowrap"><?= e($emp['emp_number']) ?></td>
          <td class="strong"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?><br>
              <span class="small muted"><?= e($emp['email']) ?></span></td>
          <td><?= e($emp['dept_name'] ?? '—') ?></td>
          <td><?= e($emp['position_title'] ?? '—') ?></td>
          <td class="nowrap"><?= fdate($emp['hire_date']) ?></td>
          <td class="num"><?= money($emp['basic_salary']) ?></td>
          <td><?= status_badge($emp['status']) ?></td>
          <td>
            <div class="row-actions">
              <a class="btn secondary small" href="<?= url('modules/employees/view.php?id=' . $emp['employee_id']) ?>">View</a>
              <?php if (has_role(['admin','hr'])): ?>
                <a class="btn secondary small" href="<?= url('modules/employees/form.php?id=' . $emp['employee_id']) ?>">Edit</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
