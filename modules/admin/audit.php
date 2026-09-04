<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin']);

$pageTitle = 'Audit trail';
$module    = $_GET['module'] ?? '';
$action    = $_GET['action'] ?? '';
$from      = $_GET['from'] ?? date('Y-m-01');
$to        = $_GET['to']   ?? date('Y-m-d');

$sql  = 'SELECT * FROM audit_logs WHERE DATE(created_at) BETWEEN ? AND ?';
$args = [$from, $to];
if ($module !== '') { $sql .= ' AND module = ?'; $args[] = $module; }
if ($action !== '') { $sql .= ' AND action = ?'; $args[] = $action; }
$sql .= ' ORDER BY created_at DESC LIMIT 400';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$logs = $stmt->fetchAll();

$modules = $pdo->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= count($logs) ?> entries</h2>
    <div class="spacer"></div>
    <span class="small muted">Newest first, capped at 400 rows</span>
  </div>

  <form method="get" class="panel-body filters">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label for="module">Module</label>
      <select id="module" name="module">
        <option value="">All modules</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="action">Action</label>
      <select id="action" name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>&nbsp;</label><button class="btn">Apply</button></div>
    <div class="field"><label>&nbsp;</label><a class="btn secondary" href="<?= url('modules/admin/audit.php') ?>">Reset</a></div>
  </form>

  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>Description</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="7" class="muted">No activity recorded in this range.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="nowrap small"><?= fdate($l['created_at'], 'd M Y H:i:s') ?></td>
          <td><?= e($l['username']) ?></td>
          <td><span class="badge <?= in_array($l['action'], ['DELETE','LOGIN_FAILED','REJECTED'], true) ? 'bad' : 'neutral' ?>"><?= e($l['action']) ?></span></td>
          <td><?= e($l['module']) ?></td>
          <td class="num"><?= $l['record_id'] ?: '—' ?></td>
          <td class="small"><?= e($l['description']) ?></td>
          <td class="small muted"><?= e($l['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
