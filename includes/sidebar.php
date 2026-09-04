<?php
$current = $_SERVER['SCRIPT_NAME'];
/** Marks the link active when the current script matches. */
function nav_active(string $path): string {
    return strpos($_SERVER['SCRIPT_NAME'], '/' . ltrim($path, '/')) !== false ? ' class="active"' : '';
}
?>
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <strong><?= APP_NAME ?></strong>
    <span>Performance &amp; payroll</span>
  </div>
  <nav>
    <a href="<?= url('index.php') ?>"<?= nav_active('index.php') ?>><span class="ic">&#9632;</span> Dashboard</a>

    <?php if (has_role(['admin','hr','manager'])): ?>
      <div class="nav-group">People</div>
      <a href="<?= url('modules/employees/index.php') ?>"<?= nav_active('modules/employees') ?>><span class="ic">&#128100;</span> Employees</a>
      <a href="<?= url('modules/attendance/index.php') ?>"<?= nav_active('modules/attendance') ?>><span class="ic">&#128337;</span> Attendance</a>
      <a href="<?= url('modules/leave/index.php') ?>"<?= nav_active('modules/leave') ?>><span class="ic">&#128197;</span> Leave</a>
    <?php else: ?>
      <div class="nav-group">My workspace</div>
      <a href="<?= url('modules/attendance/index.php') ?>"<?= nav_active('modules/attendance') ?>><span class="ic">&#128337;</span> My attendance</a>
      <a href="<?= url('modules/leave/index.php') ?>"<?= nav_active('modules/leave') ?>><span class="ic">&#128197;</span> My leave</a>
    <?php endif; ?>

    <div class="nav-group">Performance</div>
    <a href="<?= url('modules/performance/index.php') ?>"<?= nav_active('modules/performance/index') ?>><span class="ic">&#9733;</span> Appraisals</a>
    <?php if (has_role(['admin','hr'])): ?>
      <a href="<?= url('modules/performance/periods.php') ?>"<?= nav_active('performance/periods') ?>><span class="ic">&#9711;</span> Review periods</a>
      <a href="<?= url('modules/performance/criteria.php') ?>"<?= nav_active('performance/criteria') ?>><span class="ic">&#9776;</span> KPIs &amp; bonus rules</a>
    <?php endif; ?>

    <div class="nav-group">Payroll</div>
    <?php if (has_role(['admin','hr'])): ?>
      <a href="<?= url('modules/payroll/index.php') ?>"<?= nav_active('payroll/index') ?>><span class="ic">&#128176;</span> Payroll runs</a>
    <?php endif; ?>
    <a href="<?= url('modules/payroll/payslips.php') ?>"<?= nav_active('payroll/payslips') ?>><span class="ic">&#128196;</span> Payslips</a>

    <?php if (has_role(['admin','hr','manager'])): ?>
      <div class="nav-group">Insight</div>
      <a href="<?= url('modules/reports/index.php') ?>"<?= nav_active('modules/reports') ?>><span class="ic">&#128200;</span> Reports</a>
    <?php endif; ?>

    <?php if (has_role(['admin'])): ?>
      <div class="nav-group">Administration</div>
      <a href="<?= url('modules/users/index.php') ?>"<?= nav_active('modules/users') ?>><span class="ic">&#128273;</span> User accounts</a>
      <a href="<?= url('modules/admin/settings.php') ?>"<?= nav_active('admin/settings') ?>><span class="ic">&#9881;</span> Settings</a>
      <a href="<?= url('modules/admin/audit.php') ?>"<?= nav_active('admin/audit') ?>><span class="ic">&#128209;</span> Audit trail</a>
    <?php endif; ?>
  </nav>
</aside>
