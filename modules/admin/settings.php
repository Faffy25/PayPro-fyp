<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin']);

$pageTitle = 'Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $upd = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($_POST['s'] ?? [] as $key => $value) {
            $upd->execute([trim($value), $key]);
        }
        log_action('UPDATE', 'Settings', null, 'Updated system settings');
        set_flash('success', 'Settings saved. New payroll runs will use these values.');
    }

    if ($action === 'save_bands') {
        $upd = $pdo->prepare('UPDATE tax_bands SET min_amount = ?, max_amount = ?, rate = ?, deduction = ? WHERE band_id = ?');
        foreach ($_POST['b'] ?? [] as $bid => $b) {
            $max = $b['max_amount'] === '' ? null : (float)$b['max_amount'];
            $upd->execute([(float)$b['min_amount'], $max, (float)$b['rate'] / 100, (float)$b['deduction'], (int)$bid]);
        }
        log_action('UPDATE', 'Settings', null, 'Updated PAYE tax bands');
        set_flash('success', 'Tax bands updated.');
    }

    if ($action === 'add_department') {
        $pdo->prepare('INSERT INTO departments (dept_code, dept_name, description) VALUES (?,?,?)')
            ->execute([strtoupper(trim($_POST['dept_code'])), trim($_POST['dept_name']), trim($_POST['description'])]);
        log_action('CREATE', 'Settings', (int)$pdo->lastInsertId(), 'Added department ' . $_POST['dept_name']);
        set_flash('success', 'Department added.');
    }
    redirect('modules/admin/settings.php');
}

$settings    = $pdo->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll();
$bands       = $pdo->query('SELECT * FROM tax_bands ORDER BY min_amount')->fetchAll();
$departments = $pdo->query(
    "SELECT d.*, COUNT(e.employee_id) AS staff FROM departments d
     LEFT JOIN employees e ON e.department_id = d.department_id AND e.status = 'Active'
     GROUP BY d.department_id ORDER BY d.dept_name"
)->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head"><h2>Company and payroll settings</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <div class="form-grid">
      <?php foreach ($settings as $s): ?>
        <div class="field">
          <label for="s_<?= e($s['setting_key']) ?>"><?= e(ucfirst(str_replace('_', ' ', $s['setting_key']))) ?></label>
          <input type="text" id="s_<?= e($s['setting_key']) ?>" name="s[<?= e($s['setting_key']) ?>]" value="<?= e($s['setting_value']) ?>">
          <span class="hint"><?= e($s['description']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn">Save settings</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>PAYE bands</h2>
    <div class="spacer"></div>
    <span class="small muted">Monthly <?= e(setting('currency','USD')) ?> amounts</span>
  </div>
  <div class="panel-body">
    <div class="alert warning" style="margin:0 0 1rem">
      These bands ship with illustrative figures. Replace them with the current ZIMRA tax tables
      before using the system for real payroll.
    </div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_bands">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>From</th><th>To</th><th>Rate %</th><th>Less deduction</th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b): ?>
          <tr>
            <td><input type="number" step="0.01" name="b[<?= $b['band_id'] ?>][min_amount]" value="<?= e($b['min_amount']) ?>"></td>
            <td><input type="number" step="0.01" name="b[<?= $b['band_id'] ?>][max_amount]" value="<?= $b['max_amount'] === null ? '' : e($b['max_amount']) ?>" placeholder="No limit"></td>
            <td><input type="number" step="0.01" name="b[<?= $b['band_id'] ?>][rate]" value="<?= e($b['rate'] * 100) ?>"></td>
            <td><input type="number" step="0.01" name="b[<?= $b['band_id'] ?>][deduction]" value="<?= e($b['deduction']) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-body"><button class="btn">Save tax bands</button>
      <span class="hint">Tax = taxable income &times; rate &minus; deduction, then the AIDS levy is added.</span>
    </div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>Departments</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Code</th><th>Name</th><th>Description</th><th class="num">Staff</th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td class="strong"><?= e($d['dept_code']) ?></td>
          <td><?= e($d['dept_name']) ?></td>
          <td class="small muted"><?= e($d['description']) ?></td>
          <td class="num"><?= (int)$d['staff'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_department">
    <div class="filters">
      <div class="field"><label for="dept_code">Code</label><input type="text" id="dept_code" name="dept_code" maxlength="10" required></div>
      <div class="field"><label for="dept_name">Name</label><input type="text" id="dept_name" name="dept_name" required></div>
      <div class="field" style="flex:1"><label for="description">Description</label><input type="text" id="description" name="description"></div>
      <div class="field"><label>&nbsp;</label><button class="btn">Add department</button></div>
    </div>
  </form>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
