<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin','hr']);

$pageTitle = 'Review periods';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name']);
        $start = $_POST['start_date'];
        $end   = $_POST['end_date'];
        if ($name === '' || strtotime($end) < strtotime($start)) {
            set_flash('error', 'Give the period a name and make sure the end date is after the start date.');
        } else {
            $pdo->prepare('INSERT INTO appraisal_periods (name, start_date, end_date) VALUES (?,?,?)')
                ->execute([$name, $start, $end]);
            log_action('CREATE', 'Performance', (int)$pdo->lastInsertId(), "Opened review period $name");
            notify_roles(['manager','hr'], 'Review period opened', "$name is now open for appraisals.", 'modules/performance/index.php');
            set_flash('success', 'Review period opened.');
        }
    }

    if ($action === 'toggle') {
        $pid = (int)$_POST['period_id'];
        $stmt = $pdo->prepare('SELECT * FROM appraisal_periods WHERE period_id = ?');
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if ($p) {
            $new = $p['status'] === 'Open' ? 'Closed' : 'Open';
            $pdo->prepare('UPDATE appraisal_periods SET status = ? WHERE period_id = ?')->execute([$new, $pid]);
            log_action('UPDATE', 'Performance', $pid, "Set period {$p['name']} to $new");
            set_flash('success', "Period {$p['name']} is now $new.");
        }
    }
    redirect('modules/performance/periods.php');
}

$periods = $pdo->query(
    'SELECT p.*, COUNT(a.appraisal_id) AS appraisals,
            SUM(a.status = "Approved") AS approved, AVG(a.total_score) AS avg_score
     FROM appraisal_periods p
     LEFT JOIN performance_appraisals a ON a.period_id = p.period_id
     GROUP BY p.period_id ORDER BY p.end_date DESC'
)->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head"><h2>Open a new review period</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="filters">
      <div class="field" style="min-width:240px">
        <label for="name">Period name</label>
        <input type="text" id="name" name="name" placeholder="H2 2026 Review" required>
      </div>
      <div class="field">
        <label for="start_date">Starts</label>
        <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-01') ?>" required>
      </div>
      <div class="field">
        <label for="end_date">Ends</label>
        <input type="date" id="end_date" name="end_date" value="<?= date('Y-m-t') ?>" required>
      </div>
      <div class="field"><label>&nbsp;</label><button class="btn">Open period</button></div>
    </div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>All periods</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Window</th><th class="num">Appraisals</th><th class="num">Approved</th>
                 <th class="num">Average</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($periods as $p): ?>
        <tr>
          <td class="strong"><?= e($p['name']) ?></td>
          <td class="nowrap"><?= fdate($p['start_date']) ?> &ndash; <?= fdate($p['end_date']) ?></td>
          <td class="num"><?= (int)$p['appraisals'] ?></td>
          <td class="num"><?= (int)$p['approved'] ?></td>
          <td class="num"><?= $p['avg_score'] ? money($p['avg_score']) : '—' ?></td>
          <td><?= $p['status'] === 'Open' ? '<span class="badge ok">Open</span>' : '<span class="badge neutral">Closed</span>' ?></td>
          <td class="right">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="period_id" value="<?= $p['period_id'] ?>">
              <button class="btn secondary small"><?= $p['status'] === 'Open' ? 'Close period' : 'Reopen' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
