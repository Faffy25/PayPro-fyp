<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$appraisalId = (int)($_GET['id'] ?? 0);
$employeeId  = (int)($_GET['employee_id'] ?? 0);
$periodId    = (int)($_GET['period_id'] ?? 0);
$isOfficer   = has_role(['admin','hr','manager']);

$criteria = $pdo->query('SELECT * FROM kpi_criteria WHERE active = 1 ORDER BY criteria_id')->fetchAll();
$rules    = $pdo->query('SELECT * FROM bonus_rules ORDER BY min_score DESC')->fetchAll();

/* ------------------------------------------------------------------
   Load existing appraisal, or prepare a blank one
------------------------------------------------------------------ */
$appraisal = null;
if ($appraisalId) {
    $stmt = $pdo->prepare(
        'SELECT a.*, e.first_name, e.last_name, e.emp_number, e.basic_salary, p.name AS period_name
         FROM performance_appraisals a
         JOIN employees e ON e.employee_id = a.employee_id
         JOIN appraisal_periods p ON p.period_id = a.period_id
         WHERE a.appraisal_id = ?'
    );
    $stmt->execute([$appraisalId]);
    $appraisal  = $stmt->fetch();
    if (!$appraisal) { set_flash('error', 'Appraisal not found.'); redirect('modules/performance/index.php'); }
    $employeeId = (int)$appraisal['employee_id'];
    $periodId   = (int)$appraisal['period_id'];
} elseif ($employeeId) {
    $stmt = $pdo->prepare('SELECT appraisal_id FROM performance_appraisals WHERE employee_id = ? AND period_id = ?');
    $stmt->execute([$employeeId, $periodId]);
    if ($existing = $stmt->fetchColumn()) {
        redirect('modules/performance/appraise.php?id=' . $existing);
    }
}

// Employees may only open their own appraisal, and only to read it.
if (!$isOfficer && $employeeId !== (int)(current_user()['employee_id'] ?? 0)) {
    require_role(['admin','hr','manager']);
}

$stmt = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) { set_flash('error', 'Select an employee to appraise.'); redirect('modules/performance/index.php'); }

$stmt = $pdo->prepare('SELECT * FROM appraisal_periods WHERE period_id = ?');
$stmt->execute([$periodId]);
$period = $stmt->fetch();

// Existing scores keyed by criteria
$scores = [];
if ($appraisalId) {
    $stmt = $pdo->prepare('SELECT * FROM appraisal_scores WHERE appraisal_id = ?');
    $stmt->execute([$appraisalId]);
    foreach ($stmt->fetchAll() as $s) $scores[$s['criteria_id']] = $s;
}

/* ------------------------------------------------------------------
   Save
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role(['admin','hr','manager']);
    $submitAs = $_POST['save_as'] ?? 'Draft';       // Draft | Submitted | Approved
    if ($submitAs === 'Approved' && !has_role(['admin','hr'])) {
        set_flash('error', 'Only HR or an administrator can approve an appraisal.');
        $submitAs = 'Submitted';
    }

    // Weighted score: each criterion contributes (score / max) x weight.
    $total = 0.0;
    foreach ($criteria as $c) {
        $raw    = (float)($_POST['score'][$c['criteria_id']] ?? 0);
        $raw    = max(0, min($raw, (float)$c['max_score']));
        $total += ($raw / (float)$c['max_score']) * (float)$c['weight'];
    }
    $total = round($total, 2);
    $bonus = bonus_for_score($total);

    $pdo->beginTransaction();
    try {
        if ($appraisalId) {
            $pdo->prepare(
                'UPDATE performance_appraisals
                 SET total_score = ?, rating = ?, bonus_percentage = ?, strengths = ?, improvements = ?,
                     status = ?, reviewer_id = ?
                 WHERE appraisal_id = ?'
            )->execute([
                $total, $bonus['rating'], $bonus['bonus_percentage'],
                trim($_POST['strengths'] ?? ''), trim($_POST['improvements'] ?? ''),
                $submitAs, current_user()['user_id'], $appraisalId,
            ]);
            $pdo->prepare('DELETE FROM appraisal_scores WHERE appraisal_id = ?')->execute([$appraisalId]);
        } else {
            $pdo->prepare(
                'INSERT INTO performance_appraisals
                 (employee_id, period_id, reviewer_id, total_score, rating, bonus_percentage, strengths, improvements, status)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $employeeId, $periodId, current_user()['user_id'], $total,
                $bonus['rating'], $bonus['bonus_percentage'],
                trim($_POST['strengths'] ?? ''), trim($_POST['improvements'] ?? ''), $submitAs,
            ]);
            $appraisalId = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO appraisal_scores (appraisal_id, criteria_id, score, remark) VALUES (?,?,?,?)');
        foreach ($criteria as $c) {
            $raw = max(0, min((float)($_POST['score'][$c['criteria_id']] ?? 0), (float)$c['max_score']));
            $ins->execute([$appraisalId, $c['criteria_id'], $raw, trim($_POST['remark'][$c['criteria_id']] ?? '')]);
        }
        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        set_flash('error', 'Could not save the appraisal: ' . e($ex->getMessage()));
        redirect('modules/performance/index.php');
    }

    log_action($submitAs === 'Approved' ? 'APPROVE' : 'UPDATE', 'Performance', $appraisalId,
        "Appraisal for {$employee['first_name']} {$employee['last_name']} scored $total ({$bonus['rating']})");

    if ($submitAs === 'Approved') {
        $uid = $pdo->prepare('SELECT user_id FROM users WHERE employee_id = ?');
        $uid->execute([$employeeId]);
        if ($u = $uid->fetchColumn()) {
            notify((int)$u, 'Appraisal approved',
                "You scored $total out of 100 ({$bonus['rating']}). Bonus of {$bonus['bonus_percentage']}% applies to the next payroll run.",
                'modules/performance/index.php');
        }
    }

    set_flash('success', "Appraisal saved as $submitAs. Score $total, rating {$bonus['rating']}, bonus {$bonus['bonus_percentage']}%.");
    redirect('modules/performance/appraise.php?id=' . $appraisalId);
}

$readOnly  = !$isOfficer || ($appraisal && $appraisal['status'] === 'Approved' && !has_role(['admin','hr']));
$pageTitle = 'Appraisal · ' . $employee['first_name'] . ' ' . $employee['last_name'];
$cur       = setting('currency', 'USD');
include APP_ROOT . '/includes/header.php';
?>

<section class="panel">
  <div class="panel-head">
    <h2><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?> &middot; <?= e($period['name'] ?? 'No period') ?></h2>
    <?= $appraisal ? status_badge($appraisal['status']) : '<span class="badge neutral">New</span>' ?>
    <div class="spacer"></div>
    <a class="btn secondary small" href="<?= url('modules/performance/index.php?period_id=' . $periodId) ?>">Back to list</a>
  </div>
  <div class="panel-body grid-3">
    <div><span class="hint">Employee number</span><br><?= e($employee['emp_number']) ?></div>
    <div><span class="hint">Basic salary</span><br><?= e($cur) ?> <?= money($employee['basic_salary']) ?></div>
    <div><span class="hint">Review window</span><br><?= fdate($period['start_date'] ?? null) ?> &ndash; <?= fdate($period['end_date'] ?? null) ?></div>
  </div>
</section>

<form method="post">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-head"><h2>Rate each area</h2>
      <div class="spacer"></div>
      <span class="small muted">Score out of the maximum shown; weights total 100.</span>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Criterion</th><th class="num">Weight</th><th style="width:110px">Score</th><th>Comment</th></tr></thead>
        <tbody>
        <?php foreach ($criteria as $c): $s = $scores[$c['criteria_id']] ?? null; ?>
          <tr>
            <td class="strong"><?= e($c['name']) ?><br><span class="small muted"><?= e($c['description']) ?></span></td>
            <td class="num"><?= money($c['weight']) ?>%</td>
            <td>
              <input type="number" class="kpi-score" step="0.5" min="0" max="<?= (int)$c['max_score'] ?>"
                     data-max="<?= (int)$c['max_score'] ?>" data-weight="<?= (float)$c['weight'] ?>"
                     name="score[<?= $c['criteria_id'] ?>]"
                     value="<?= $s ? e($s['score']) : '' ?>" <?= $readOnly ? 'readonly' : '' ?>>
              <span class="hint">of <?= (int)$c['max_score'] ?></span>
            </td>
            <td><input type="text" name="remark[<?= $c['criteria_id'] ?>]" value="<?= $s ? e($s['remark']) : '' ?>"
                       placeholder="Evidence or example" <?= $readOnly ? 'readonly' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="grid-2">
    <section class="panel">
      <div class="panel-head"><h2>Reviewer comments</h2></div>
      <div class="panel-body stack">
        <div class="field">
          <label for="strengths">What went well</label>
          <textarea id="strengths" name="strengths" <?= $readOnly ? 'readonly' : '' ?>><?= e($appraisal['strengths'] ?? '') ?></textarea>
        </div>
        <div class="field">
          <label for="improvements">Where to improve</label>
          <textarea id="improvements" name="improvements" <?= $readOnly ? 'readonly' : '' ?>><?= e($appraisal['improvements'] ?? '') ?></textarea>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Result and pay effect</h2></div>
      <div class="panel-body">
        <div class="metrics" style="margin:0">
          <div class="metric">
            <div class="label">Weighted score</div>
            <div class="value" id="liveScore" style="font-size:1.35rem"><?= $appraisal ? money($appraisal['total_score']) . ' / 100' : '0.00 / 100' ?></div>
          </div>
          <div class="metric blue">
            <div class="label">Rating</div>
            <div class="value" id="liveRating" style="font-size:1.05rem"><?= e($appraisal['rating'] ?? '—') ?></div>
          </div>
          <div class="metric gold">
            <div class="label">Bonus earned</div>
            <div class="value" id="liveBonus" style="font-size:1.05rem">
              <?= $appraisal ? money($appraisal['bonus_percentage']) . '% of basic salary' : '—' ?>
            </div>
          </div>
        </div>
        <p class="hint" style="margin-top:.75rem">
          Once approved, this bonus is picked up automatically by the next payroll run and
          shown as a separate line on the payslip.
        </p>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Score range</th><th>Rating</th><th class="num">Bonus</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): ?>
              <tr>
                <td><?= money($r['min_score']) ?> &ndash; <?= money($r['max_score']) ?></td>
                <td><?= e($r['rating']) ?></td>
                <td class="num"><?= money($r['bonus_percentage']) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>

  <?php if (!$readOnly): ?>
  <div class="panel panel-body form-actions">
    <button class="btn secondary" name="save_as" value="Draft">Save draft</button>
    <button class="btn" name="save_as" value="Submitted">Submit for approval</button>
    <?php if (has_role(['admin','hr'])): ?>
      <button class="btn" name="save_as" value="Approved"
              data-confirm="Approving locks the score and releases the bonus to payroll. Continue?">Approve</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</form>

<script>window.PAYPRO_BONUS_RULES = <?= json_encode($rules) ?>;</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
