<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin','hr']);

$pageTitle = 'KPIs and bonus rules';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_criteria') {
        $pdo->prepare('INSERT INTO kpi_criteria (name, description, weight, max_score) VALUES (?,?,?,?)')
            ->execute([trim($_POST['name']), trim($_POST['description']), (float)$_POST['weight'], (int)$_POST['max_score']]);
        log_action('CREATE', 'Performance', (int)$pdo->lastInsertId(), 'Added KPI ' . $_POST['name']);
        set_flash('success', 'Criterion added.');
    }

    if ($action === 'save_criteria') {
        $upd = $pdo->prepare('UPDATE kpi_criteria SET weight = ?, max_score = ?, active = ? WHERE criteria_id = ?');
        foreach ($_POST['c'] ?? [] as $cid => $c) {
            $upd->execute([(float)$c['weight'], (int)$c['max_score'], isset($c['active']) ? 1 : 0, (int)$cid]);
        }
        log_action('UPDATE', 'Performance', null, 'Updated KPI weights');
        set_flash('success', 'Criteria updated.');
    }

    if ($action === 'save_rules') {
        $upd = $pdo->prepare('UPDATE bonus_rules SET min_score = ?, max_score = ?, bonus_percentage = ? WHERE rule_id = ?');
        foreach ($_POST['r'] ?? [] as $rid => $r) {
            $upd->execute([(float)$r['min_score'], (float)$r['max_score'], (float)$r['bonus_percentage'], (int)$rid]);
        }
        log_action('UPDATE', 'Performance', null, 'Updated bonus rules');
        set_flash('success', 'Bonus rules updated. New payroll runs will use them.');
    }
    redirect('modules/performance/criteria.php');
}

$criteria   = $pdo->query('SELECT * FROM kpi_criteria ORDER BY criteria_id')->fetchAll();
$rules      = $pdo->query('SELECT * FROM bonus_rules ORDER BY min_score DESC')->fetchAll();
$weightSum  = array_sum(array_map(fn($c) => $c['active'] ? (float)$c['weight'] : 0, $criteria));

include APP_ROOT . '/includes/header.php';
?>

<?php if (abs($weightSum - 100) > 0.01): ?>
  <div class="alert warning">
    Active weights add up to <?= money($weightSum) ?>%, not 100%. Scores will still calculate, but
    they will not sit on a clean 100-point scale until the weights balance.
  </div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>Appraisal criteria</h2>
    <div class="spacer"></div><span class="small muted">Total active weight: <?= money($weightSum) ?>%</span>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_criteria">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Criterion</th><th style="width:110px">Weight %</th><th style="width:110px">Max score</th><th style="width:90px">Active</th></tr></thead>
        <tbody>
        <?php foreach ($criteria as $c): ?>
          <tr>
            <td class="strong"><?= e($c['name']) ?><br><span class="small muted"><?= e($c['description']) ?></span></td>
            <td><input type="number" step="0.5" min="0" name="c[<?= $c['criteria_id'] ?>][weight]" value="<?= e($c['weight']) ?>"></td>
            <td><input type="number" min="1" name="c[<?= $c['criteria_id'] ?>][max_score]" value="<?= (int)$c['max_score'] ?>"></td>
            <td><input type="checkbox" name="c[<?= $c['criteria_id'] ?>][active]" <?= $c['active'] ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-body"><button class="btn">Save criteria</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>Add a criterion</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_criteria">
    <div class="form-grid">
      <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" required></div>
      <div class="field"><label for="description">Description</label><input type="text" id="description" name="description"></div>
      <div class="field"><label for="weight">Weight %</label><input type="number" step="0.5" id="weight" name="weight" value="10" required></div>
      <div class="field"><label for="max_score">Max score</label><input type="number" id="max_score" name="max_score" value="5" required></div>
    </div>
    <div class="form-actions"><button class="btn">Add criterion</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>Score to bonus</h2>
    <div class="spacer"></div>
    <span class="small muted">This table is what connects performance to pay.</span>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_rules">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Rating</th><th style="width:120px">From</th><th style="width:120px">To</th><th style="width:140px">Bonus % of basic</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td class="strong"><?= e($r['rating']) ?></td>
            <td><input type="number" step="0.01" name="r[<?= $r['rule_id'] ?>][min_score]" value="<?= e($r['min_score']) ?>"></td>
            <td><input type="number" step="0.01" name="r[<?= $r['rule_id'] ?>][max_score]" value="<?= e($r['max_score']) ?>"></td>
            <td><input type="number" step="0.01" name="r[<?= $r['rule_id'] ?>][bonus_percentage]" value="<?= e($r['bonus_percentage']) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-body"><button class="btn">Save bonus rules</button></div>
  </form>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
