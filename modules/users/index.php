<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin']);

$pageTitle = 'User accounts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $roleId   = (int)$_POST['role_id'];
        $empId    = $_POST['employee_id'] !== '' ? (int)$_POST['employee_id'] : null;
        $password = $_POST['password'];

        if (strlen($password) < 8) {
            set_flash('error', 'The password must be at least 8 characters.');
        } elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Enter a username and a valid email address.');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (employee_id, username, email, password_hash, role_id) VALUES (?,?,?,?,?)')
                    ->execute([$empId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
                $newId = (int)$pdo->lastInsertId();
                log_action('CREATE', 'Users', $newId, "Created account $username");
                set_flash('success', "Account $username created.");
            } catch (PDOException $ex) {
                set_flash('error', 'That username, email or employee is already linked to an account.');
            }
        }
    }

    if ($action === 'reset') {
        $uid = (int)$_POST['user_id'];
        $new = $_POST['new_password'];
        if (strlen($new) < 8) {
            set_flash('error', 'The new password must be at least 8 characters.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_action('UPDATE', 'Users', $uid, 'Reset password');
            notify($uid, 'Password changed', 'An administrator reset your password. Sign in with the new one.');
            set_flash('success', 'Password reset.');
        }
    }

    if ($action === 'toggle') {
        $uid = (int)$_POST['user_id'];
        if ($uid === (int)current_user()['user_id']) {
            set_flash('error', 'You cannot disable the account you are signed in with.');
        } else {
            $pdo->prepare("UPDATE users SET status = IF(status = 'Active', 'Disabled', 'Active') WHERE user_id = ?")
                ->execute([$uid]);
            log_action('UPDATE', 'Users', $uid, 'Changed account status');
            set_flash('success', 'Account status changed.');
        }
    }

    if ($action === 'change_role') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')->execute([(int)$_POST['role_id'], $uid]);
        log_action('UPDATE', 'Users', $uid, 'Changed role');
        set_flash('success', 'Role updated.');
    }
    redirect('modules/users/index.php');
}

$users = $pdo->query(
    'SELECT u.*, r.role_name, e.first_name, e.last_name, e.emp_number
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN employees e ON e.employee_id = u.employee_id
     ORDER BY r.role_id, u.username'
)->fetchAll();

$roles     = $pdo->query('SELECT * FROM roles ORDER BY role_id')->fetchAll();
$unlinked  = $pdo->query(
    "SELECT e.employee_id, e.emp_number, e.first_name, e.last_name
     FROM employees e LEFT JOIN users u ON u.employee_id = e.employee_id
     WHERE u.user_id IS NULL AND e.status = 'Active' ORDER BY e.emp_number"
)->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head"><h2>Create an account</h2>
    <div class="spacer"></div>
    <span class="small muted"><?= count($unlinked) ?> employees have no login yet</span>
  </div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <div class="field"><label for="username">Username</label>
        <input type="text" id="username" name="username" required></div>
      <div class="field"><label for="email">Email</label>
        <input type="email" id="email" name="email" required></div>
      <div class="field"><label for="password">Temporary password</label>
        <input type="text" id="password" name="password" value="Password@123" required>
        <span class="hint">At least 8 characters. Ask the user to change it after signing in.</span></div>
      <div class="field"><label for="role_id">Role</label>
        <select id="role_id" name="role_id">
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r['role_id'] ?>"><?= e(ucfirst($r['role_name'])) ?> — <?= e($r['description']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field full"><label for="employee_id">Link to employee record</label>
        <select id="employee_id" name="employee_id">
          <option value="">No staff record (system account)</option>
          <?php foreach ($unlinked as $emp): ?>
            <option value="<?= $emp['employee_id'] ?>"><?= e($emp['emp_number'] . ' — ' . $emp['first_name'] . ' ' . $emp['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Linking lets the user see their own payslips, leave and appraisals.</span></div>
    </div>
    <div class="form-actions"><button class="btn">Create account</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2><?= count($users) ?> accounts</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Username</th><th>Staff record</th><th>Role</th><th>Last sign-in</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="strong"><?= e($u['username']) ?><br><span class="small muted"><?= e($u['email']) ?></span></td>
          <td><?= $u['first_name'] ? e($u['first_name'] . ' ' . $u['last_name']) . '<br><span class="small muted">' . e($u['emp_number']) . '</span>' : '<span class="muted">—</span>' ?></td>
          <td>
            <form method="post" style="display:flex;gap:.3rem">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
              <select name="role_id" class="small">
                <?php foreach ($roles as $r): ?>
                  <option value="<?= $r['role_id'] ?>" <?= $u['role_id'] == $r['role_id'] ? 'selected' : '' ?>><?= e(ucfirst($r['role_name'])) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn secondary small">Set</button>
            </form>
          </td>
          <td class="nowrap small"><?= $u['last_login'] ? fdate($u['last_login'], 'd M Y H:i') : 'Never' ?></td>
          <td><?= status_badge($u['status']) ?></td>
          <td>
            <div class="row-actions">
              <form method="post" style="display:flex;gap:.3rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <input type="text" name="new_password" placeholder="New password" style="width:130px">
                <button class="btn secondary small">Reset</button>
              </form>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <button class="btn secondary small"><?= $u['status'] === 'Active' ? 'Disable' : 'Enable' ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
