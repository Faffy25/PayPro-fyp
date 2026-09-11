<?php
require_once __DIR__ . '/config/config.php';
if (is_logged_in()) redirect('index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?> &middot; <?= APP_LONG_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>

<header class="landing-nav">
  <div class="mark"><?= APP_NAME ?></div>
  <nav>
    <a class="btn secondary small" href="<?= url('login.php') ?>">Sign in</a>
    <a class="btn small" href="<?= url('register.php') ?>">Create an account</a>
  </nav>
</header>

<section class="landing-hero">
  <span class="eyebrow">Performance &amp; payroll, one login</span>
  <h1>Pay people fairly, on time, every time.</h1>
  <p class="lead">
    <?= APP_NAME ?> links a manager's rating to next month's payslip, keeps attendance and leave
    in one place, and writes down every change &mdash; so payday never depends on a spreadsheet.
  </p>
  <div class="cta">
    <a class="btn" href="<?= url('register.php') ?>">Create an account</a>
    <a class="btn secondary" href="<?= url('login.php') ?>">Sign in</a>
  </div>
  <div class="landing-chips">
    <span>PAYE</span><span>AIDS levy</span><span>NSSA</span><span>QR attendance</span><span>Audit trail</span>
  </div>
</section>

<section class="landing-features">
  <h2>Everything HR needs, one login</h2>
  <p class="sub">From the day someone joins to the payslip that lands at month end.</p>
  <div class="landing-grid">
    <div class="landing-card">
      <div class="ic">&#128100;</div>
      <h3>Staff records</h3>
      <p>Contracts, departments, positions and salary structure in one directory.</p>
    </div>
    <div class="landing-card">
      <div class="ic">&#9635;</div>
      <h3>QR attendance</h3>
      <p>Staff scan a code to clock in and out; HR sees who's in the moment it happens.</p>
    </div>
    <div class="landing-card">
      <div class="ic">&#128197;</div>
      <h3>Leave, with approval</h3>
      <p>Employees apply, managers approve or reject, balances are checked automatically.</p>
    </div>
    <div class="landing-card">
      <div class="ic">&#9733;</div>
      <h3>KPIs &amp; bonus</h3>
      <p>Score each review against weighted criteria; the rating sets a real bonus percentage.</p>
    </div>
    <div class="landing-card">
      <div class="ic">&#128176;</div>
      <h3>Payroll runs</h3>
      <p>PAYE, AIDS levy and NSSA calculated automatically, bonus and overtime included.</p>
    </div>
    <div class="landing-card">
      <div class="ic">&#128209;</div>
      <h3>Audit trail</h3>
      <p>Every approval, edit and payroll run is logged &mdash; who, what, and when.</p>
    </div>
  </div>
</section>

<section class="landing-band">
  <h2>Ready when you are</h2>
  <p>
    Sign in with the account your administrator issued, or create a self-service account
    and ask HR to link it to your staff record.
  </p>
  <div class="cta">
    <a class="btn" href="<?= url('login.php') ?>">Sign in</a>
    <a class="btn secondary" href="<?= url('register.php') ?>">Create an account</a>
  </div>
</section>

<footer class="landing-foot">
  <?= e(setting('company_name', 'Company')) ?> &middot; <?= APP_LONG_NAME ?> &middot; <?= date('Y') ?>
</footer>

</body>
</html>
