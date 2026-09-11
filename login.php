<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) redirect('index.php');

$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Simple throttle: five failures locks the form f
    $lock = $_SESSION['lock_until'] ?? 0;
    if ($lock > time()) {
        set_flash('error', 'Too many failed attempts. Try again in ' . ($lock - time()) . ' seconds.');
    } elseif ($username === '' || $password === '') {
        set_flash('error', 'Enter both your username and password.');
    } else {
        $stmt = $pdo->prepare(
            "SELECT u.*, r.role_name, e.first_name, e.last_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN employees e ON e.employee_id = u.employee_id
             WHERE u.username = ? OR u.email = ?
             LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && $user['status'] !== 'Active') {
            set_flash('error', 'That account is disabled. Contact the administrator.');
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'user_id'     => (int)$user['user_id'],
                'username'    => $user['username'],
                'role_name'   => $user['role_name'],
                'employee_id' => $user['employee_id'] ? (int)$user['employee_id'] : null,
                'full_name'   => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'],
            ];
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')->execute([$user['user_id']]);
            log_action('LOGIN', 'Authentication', (int)$user['user_id'], 'Signed in as ' . $user['username']);
            redirect('index.php');
        } else {
            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= 5) {
                $_SESSION['lock_until'] = time() + 120;
                $_SESSION['fails'] = 0;
            }
            log_action('LOGIN_FAILED', 'Authentication', null, 'Failed sign-in for "' . $username . '"');
            set_flash('error', 'Those details did not match an account.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-page">
  <section class="auth-brand">
    <a class="mark" href="<?= url('welcome.php') ?>" style="color:inherit;text-decoration:none"><?= APP_NAME ?></a>
    <p class="tagline">Appraisals, attendance and payroll in one place, so a rating earned this quarter shows up on next month&rsquo;s payslip.</p>
    <ul>
      <li>Staff records, contracts and salary structure</li>
      <li>Attendance capture and leave approval</li>
      <li>KPI scoring that feeds the performance bonus</li>
      <li>Payroll with PAYE, AIDS levy and NSSA</li>
      <li>Audit trail of every change</li>
    </ul>
    <div class="statutory">
      <span>PAYE</span><span>AIDS levy</span><span>NSSA</span><span>Audit trail</span>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <a class="mobile-mark" href="<?= url('welcome.php') ?>" style="color:inherit;text-decoration:none;display:block"><?= APP_NAME ?></a>
      <h1>Sign in</h1>
      <p class="lead">Welcome back. Enter your details to continue.</p>

      <?php foreach (get_flashes() as $f): ?>
        <div class="alert <?= e($f['type']) ?>"><?= $f['message'] ?></div>
      <?php endforeach; ?>

      <form method="post" class="stack" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">Username or email</label>
          <input type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" required>
            <button type="button" class="pw-toggle" data-toggle="#password">Show</button>
          </div>
        </div>
        <button class="btn block" type="submit">Sign in</button>
      </form>

      <div class="auth-divider">New to <?= APP_NAME ?>?</div>
      <div class="auth-alt">
        <p>Don&rsquo;t have an account yet?</p>
        <a class="btn secondary block" href="<?= url('register.php') ?>">Create an account</a>
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
