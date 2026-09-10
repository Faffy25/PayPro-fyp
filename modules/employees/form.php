<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin', 'hr']);

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cur = setting('currency', 'USD');

$emp = [
    'emp_number' => '', 'first_name' => '', 'last_name' => '', 'national_id' => '',
    'date_of_birth' => '', 'gender' => 'Other', 'email' => '', 'phone' => '', 'address' => '',
    'department_id' => '', 'position_id' => '', 'manager_id' => '', 'hire_date' => date('Y-m-d'),
    'employment_type' => 'Permanent', 'basic_salary' => '', 'housing_allowance' => '0',
    'transport_allowance' => '0', 'bank_name' => '', 'bank_account' => '',
    'nssa_number' => '', 'tax_number' => '', 'status' => 'Active',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) { set_flash('error', 'That employee record does not exist.'); redirect('modules/employees/index.php'); }
    $emp = $found;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($emp) as $k) {
        if (array_key_exists($k, $_POST)) $emp[$k] = trim($_POST[$k]);
    }

    if ($emp['emp_number'] === '')  $errors[] = 'Employee number is required.';
    if ($emp['first_name'] === '')  $errors[] = 'First name is required.';
    if ($emp['last_name'] === '')   $errors[] = 'Last name is required.';
    if ($emp['hire_date'] === '')   $errors[] = 'Hire date is required.';
    if ($emp['email'] !== '' && !filter_var($emp['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address is not valid.';
    if (!is_numeric($emp['basic_salary']) || $emp['basic_salary'] < 0) $errors[] = 'Basic salary must be a positive number.';

    // Employee number, national ID and email must each stay unique.
    foreach (['emp_number' => 'employee number', 'national_id' => 'national ID', 'email' => 'email address'] as $col => $label) {
        if ($emp[$col] === '') continue;
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE $col = ? AND employee_id <> ?");
        $dupe->execute([$emp[$col], $id]);
        if ($dupe->fetchColumn()) $errors[] = "That $label is already in use by another employee.";
    }

    if (!$errors) {
        $fields = ['emp_number','first_name','last_name','national_id','date_of_birth','gender','email','phone',
                   'address','department_id','position_id','manager_id','hire_date','employment_type',
                   'basic_salary','housing_allowance','transport_allowance','bank_name','bank_account',
                   'nssa_number','tax_number','status'];
        $values = [];
        foreach ($fields as $f) {
            $v = $emp[$f];
            $values[] = ($v === '' && in_array($f, ['department_id','position_id','manager_id','date_of_birth','national_id'], true)) ? null : $v;
        }

        try {
            if ($id) {
                $set = implode(' = ?, ', $fields) . ' = ?';
                $stmt = $pdo->prepare("UPDATE employees SET $set WHERE employee_id = ?");
                $values[] = $id;
                $stmt->execute($values);
                log_action('UPDATE', 'Employees', $id, 'Updated record for ' . $emp['first_name'] . ' ' . $emp['last_name']);
                set_flash('success', 'Employee record updated.');
            } else {
                $cols  = implode(',', $fields);
                $marks = implode(',', array_fill(0, count($fields), '?'));
                $pdo->prepare("INSERT INTO employees ($cols) VALUES ($marks)")->execute($values);
                $id = (int)$pdo->lastInsertId();
                log_action('CREATE', 'Employees', $id, 'Added ' . $emp['first_name'] . ' ' . $emp['last_name']);
                notify_roles(['hr'], 'New employee added', $emp['first_name'] . ' ' . $emp['last_name'] . ' joined the register.');
                set_flash('success', 'Employee added.');
            }
            redirect('modules/employees/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = (($e->errorInfo[1] ?? 0) === 1062)
                ? 'Another employee already uses that employee number, national ID or email. Check those fields and try again.'
                : 'The record could not be saved. Please check the details and try again.';
        }
    }
}

$departments = $pdo->query('SELECT * FROM departments ORDER BY dept_name')->fetchAll();
$positions   = $pdo->query('SELECT * FROM positions ORDER BY title')->fetchAll();
$managers    = $pdo->query("SELECT employee_id, first_name, last_name FROM employees WHERE status = 'Active' ORDER BY first_name")->fetchAll();

$pageTitle = $id ? 'Edit employee' : 'Add employee';
include APP_ROOT . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert error">
    <strong>Fix these before saving</strong>
    <ul style="margin:.4rem 0 0 1rem"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="panel" style="padding:1.25rem">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Personal details</legend>
    <div class="form-grid">
      <div class="field">
        <label for="emp_number">Employee number</label>
        <input type="text" id="emp_number" name="emp_number" value="<?= e($emp['emp_number']) ?>" required>
      </div>
      <div class="field">
        <label for="national_id">National ID</label>
        <input type="text" id="national_id" name="national_id" value="<?= e($emp['national_id']) ?>" placeholder="63-1234567X63">
      </div>
      <div class="field">
        <label for="first_name">First name</label>
        <input type="text" id="first_name" name="first_name" value="<?= e($emp['first_name']) ?>" required>
      </div>
      <div class="field">
        <label for="last_name">Last name</label>
        <input type="text" id="last_name" name="last_name" value="<?= e($emp['last_name']) ?>" required>
      </div>
      <div class="field">
        <label for="date_of_birth">Date of birth</label>
        <input type="date" id="date_of_birth" name="date_of_birth" value="<?= e($emp['date_of_birth']) ?>">
      </div>
      <div class="field">
        <label for="gender">Gender</label>
        <select id="gender" name="gender">
          <?php foreach (['Male','Female','Other'] as $g): ?>
            <option <?= $emp['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($emp['email']) ?>">
      </div>
      <div class="field">
        <label for="phone">Phone</label>
        <input type="text" id="phone" name="phone" value="<?= e($emp['phone']) ?>">
      </div>
      <div class="field full">
        <label for="address">Home address</label>
        <input type="text" id="address" name="address" value="<?= e($emp['address']) ?>">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Employment</legend>
    <div class="form-grid">
      <div class="field">
        <label for="department_id">Department</label>
        <select id="department_id" name="department_id">
          <option value="">Not assigned</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['department_id'] ?>" <?= $emp['department_id'] == $d['department_id'] ? 'selected' : '' ?>>
              <?= e($d['dept_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="position_id">Position</label>
        <select id="position_id" name="position_id">
          <option value="">Not assigned</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= $p['position_id'] ?>" <?= $emp['position_id'] == $p['position_id'] ? 'selected' : '' ?>>
              <?= e($p['title'] . ' (grade ' . $p['grade'] . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="manager_id">Reports to</label>
        <select id="manager_id" name="manager_id">
          <option value="">No line manager</option>
          <?php foreach ($managers as $m): if ($m['employee_id'] == $id) continue; ?>
            <option value="<?= $m['employee_id'] ?>" <?= $emp['manager_id'] == $m['employee_id'] ? 'selected' : '' ?>>
              <?= e($m['first_name'] . ' ' . $m['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="hire_date">Hire date</label>
        <input type="date" id="hire_date" name="hire_date" value="<?= e($emp['hire_date']) ?>" required>
      </div>
      <div class="field">
        <label for="employment_type">Employment type</label>
        <select id="employment_type" name="employment_type">
          <?php foreach (['Permanent','Contract','Intern','Part-time'] as $t): ?>
            <option <?= $emp['employment_type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (['Active','Suspended','Terminated'] as $s): ?>
            <option <?= $emp['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Pay and statutory details</legend>
    <div class="form-grid">
      <div class="field">
        <label for="basic_salary">Basic salary (<?= e($cur) ?> per month)</label>
        <input type="number" step="0.01" min="0" class="salary-part" id="basic_salary" name="basic_salary" value="<?= e($emp['basic_salary']) ?>" required>
      </div>
      <div class="field">
        <label for="housing_allowance">Housing allowance</label>
        <input type="number" step="0.01" min="0" class="salary-part" id="housing_allowance" name="housing_allowance" value="<?= e($emp['housing_allowance']) ?>">
      </div>
      <div class="field">
        <label for="transport_allowance">Transport allowance</label>
        <input type="number" step="0.01" min="0" class="salary-part" id="transport_allowance" name="transport_allowance" value="<?= e($emp['transport_allowance']) ?>">
      </div>
      <div class="field">
        <label>Gross before bonus and overtime</label>
        <input type="text" readonly value="" id="grossPreviewBox" style="display:none">
        <p style="margin:0;font-size:1.15rem;font-weight:600"><?= e($cur) ?> <span id="grossPreview">0.00</span></p>
        <span class="hint">Recalculated as you type. PAYE and NSSA are worked out during the payroll run.</span>
      </div>
      <div class="field">
        <label for="nssa_number">NSSA number</label>
        <input type="text" id="nssa_number" name="nssa_number" value="<?= e($emp['nssa_number']) ?>">
      </div>
      <div class="field">
        <label for="tax_number">Tax number</label>
        <input type="text" id="tax_number" name="tax_number" value="<?= e($emp['tax_number']) ?>">
      </div>
      <div class="field">
        <label for="bank_name">Bank</label>
        <input type="text" id="bank_name" name="bank_name" value="<?= e($emp['bank_name']) ?>">
      </div>
      <div class="field">
        <label for="bank_account">Account number</label>
        <input type="text" id="bank_account" name="bank_account" value="<?= e($emp['bank_account']) ?>">
      </div>
    </div>
  </fieldset>

  <div class="form-actions">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Add employee' ?></button>
    <a class="btn secondary" href="<?= url('modules/employees/index.php') ?>">Cancel</a>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
