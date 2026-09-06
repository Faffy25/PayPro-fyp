<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) redirect('index.php');

$username = '';
$email    = '';
$roleName = '';
$errors   = [];

// Roles a person may pick when registering, in menu order.
$roles = $pdo->query(
    "SELECT role_id, role_name, description FROM roles
     ORDER BY FIELD(role_name,'admin','hr','manager','employee'), role_name"
)->fetchAll();
$roleIds = array_column($roles, 'role_id', 'role_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $roleName = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!preg_match('/^[A-Za-z0-9._]{3,40}$/', $username)) {
        $errors[] = 'Username must be 3–40 characters: letters, numbers, dot or underscore only.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!isset($roleIds[$roleName])) {
        $errors[] = 'Choose the role for this account.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include a letter and a number.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        // Check for an existing username or email up front for a friendly message.
        $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($row = $stmt->fetch()) {
            $errors[] = strcasecmp($row['email'], $email) === 0
                ? 'An account already uses that email address.'
                : 'That username is already taken.';
        }
    }

    if (!$errors) {
        $roleId = (int)$roleIds[$roleName];
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (employee_id, username, email, password_hash, role_id, status)
                 VALUES (NULL, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                $roleId,
                'Active',
            ]);
            $newId = (int)$pdo->lastInsertId();

            log_action('REGISTER', 'Authentication', $newId, 'Self-registered account "' . $username . '" as ' . $roleName);
            notify_roles(
                ['admin', 'hr'],
                'New account registered',
                $username . ' (' . $email . ') registered as ' . $roleName . ' and needs a staff record linked.',
                url('modules/users/index.php')
            );

            set_flash('success', 'Your account is ready. Sign in with your new details.');
            redirect('login.php');
        } catch (PDOException $e) {
            $errors[] = 'That username or email is already registered.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create an account &middot; <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-page">
  <section class="auth-brand">
    <div class="mark"><?= APP_NAME ?></div>
    <p class="tagline">One account for your payslips, attendance, leave requests and performance reviews.</p>
    <ul>
      <li>See every payslip the moment it is issued</li>
      <li>Clock attendance and track your leave balance</li>
      <li>Follow your KPI scores and bonus rating</li>
      <li>Update your own contact details</li>
      <li>Every change recorded on the audit trail</li>
    </ul>
    <div class="statutory">
      <span>Self-service</span><span>Secure</span><span>Audited</span>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <div class="mobile-mark"><?= APP_NAME ?></div>
      <h1>Create an account</h1>
      <p class="lead">Set up self-service access. HR will link your staff record.</p>

      <?php foreach (get_flashes() as $f): ?>
        <div class="alert <?= e($f['type']) ?>"><?= $f['message'] ?></div>
      <?php endforeach; ?>
      <?php if ($errors): ?>
        <div class="alert error">
          <?php foreach ($errors as $i => $msg): ?>
            <?= $i ? '<br>' : '' ?><?= e($msg) ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="stack" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus>
        </div>
        <div class="field">
          <label for="email">Work email</label>
          <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
        </div>
        <div class="field">
          <label for="role">Role</label>
          <select id="role" name="role" required>
            <option value="" disabled <?= $roleName === '' ? 'selected' : '' ?>>Select a role&hellip;</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= e($r['role_name']) ?>"<?= $roleName === $r['role_name'] ? ' selected' : '' ?>><?= e(ucfirst($r['role_name'])) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">This decides which parts of PayPro you can open after signing in.</span>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" required minlength="8">
            <button type="button" class="pw-toggle" data-toggle="#password">Show</button>
          </div>
          <span class="hint">At least 8 characters, with a letter and a number.</span>
        </div>
        <div class="field">
          <label for="confirm">Confirm password</label>
          <div class="pw-wrap">
            <input type="password" id="confirm" name="confirm" required minlength="8">
            <button type="button" class="pw-toggle" data-toggle="#confirm">Show</button>
          </div>
        </div>
        <button class="btn block" type="submit">Create account</button>
      </form>

      <div class="auth-divider">Already registered?</div>
      <div class="auth-alt">
        <a class="btn secondary block" href="<?= url('login.php') ?>">Back to sign in</a>
      </div>

      <div class="auth-foot"><?= APP_LONG_NAME ?></div>
    </div>
  </section>
</div>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (b) {
  b.addEventListener('click', function () {
    var i = document.querySelector(b.dataset.toggle);
    if (!i) return;
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    b.textContent = show ? 'Hide' : 'Show';
  });
});
</script>
</body>
</html>
