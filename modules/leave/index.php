<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$me        = my_employee();
$isOfficer = has_role(['admin','hr','manager']);
$pageTitle = $isOfficer ? 'Leave' : 'My leave';
$filter    = $_GET['status'] ?? '';

$leaveTypes = $pdo->query('SELECT * FROM leave_types ORDER BY type_name')->fetchAll();

/* ------------------------------------------------------------------
   Actions
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // --- Submit an application ------------------------------------
    if ($action === 'apply' && $me) {
        $typeId = (int)$_POST['leave_type_id'];
        $start  = $_POST['start_date'];
        $end    = $_POST['end_date'];
        $days   = (int)$_POST['days_requested'];
        $reason = trim($_POST['reason'] ?? '');

        if (strtotime($end) < strtotime($start)) {
            set_flash('error', 'The end date cannot fall before the start date.');
        } elseif ($days < 1) {
            set_flash('error', 'The request must cover at least one working day.');
        } else {
            // Check the balance for the chosen leave type this year.
            $type = $pdo->prepare('SELECT * FROM leave_types WHERE leave_type_id = ?');
            $type->execute([$typeId]);
            $type = $type->fetch();

            $used = $pdo->prepare(
                "SELECT COALESCE(SUM(days_requested),0) FROM leave_applications
                 WHERE employee_id = ? AND leave_type_id = ? AND status IN ('Approved','Pending')
                 AND YEAR(start_date) = YEAR(?)"
            );
            $used->execute([$me['employee_id'], $typeId, $start]);
            $used = (int)$used->fetchColumn();

            if ($type['days_per_year'] > 0 && ($used + $days) > $type['days_per_year']) {
                set_flash('error', 'That exceeds your ' . e($type['type_name']) . ' entitlement. Used or pending: '
                    . $used . ' of ' . $type['days_per_year'] . ' days.');
            } else {
                $pdo->prepare(
                    'INSERT INTO leave_applications (employee_id, leave_type_id, start_date, end_date, days_requested, reason)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$me['employee_id'], $typeId, $start, $end, $days, $reason]);
                $newId = (int)$pdo->lastInsertId();
                log_action('CREATE', 'Leave', $newId, "Applied for $days day(s) of leave from $start");
                notify_roles(['hr','admin'], 'Leave request submitted',
                    $me['first_name'] . ' ' . $me['last_name'] . " requested $days day(s) from " . date('j M', strtotime($start)),
                    'modules/leave/index.php?status=Pending');
                set_flash('success', 'Application submitted. You will be notified once it is reviewed.');
            }
        }
    }

    // --- Approve or reject ----------------------------------------
    if (in_array($action, ['approve','reject'], true) && $isOfficer) {
        $leaveId = (int)$_POST['leave_id'];
        $comment = trim($_POST['review_comment'] ?? '');
        $status  = $action === 'approve' ? 'Approved' : 'Rejected';

        $stmt = $pdo->prepare(
            'SELECT l.*, e.first_name, e.last_name, u.user_id
             FROM leave_applications l
             JOIN employees e ON e.employee_id = l.employee_id
             LEFT JOIN users u ON u.employee_id = e.employee_id
             WHERE l.leave_id = ?'
        );
        $stmt->execute([$leaveId]);
        $app = $stmt->fetch();

        if ($app && $app['status'] === 'Pending') {
            $pdo->prepare(
                'UPDATE leave_applications SET status = ?, reviewed_by = ?, reviewed_on = NOW(), review_comment = ?
                 WHERE leave_id = ?'
            )->execute([$status, current_user()['user_id'], $comment, $leaveId]);

            // Approved leave writes itself into the attendance register.
            if ($status === 'Approved') {
                $mark = $pdo->prepare(
                    "INSERT INTO attendance (employee_id, work_date, status, remarks)
                     VALUES (?,?, 'On Leave', 'Approved leave')
                     ON DUPLICATE KEY UPDATE status = 'On Leave', remarks = 'Approved leave'"
                );
                $day = strtotime($app['start_date']);
                while ($day <= strtotime($app['end_date'])) {
                    if (!in_array(date('N', $day), ['6','7'], true)) {
                        $mark->execute([$app['employee_id'], date('Y-m-d', $day)]);
                    }
                    $day = strtotime('+1 day', $day);
                }
            }

            log_action(strtoupper($status), 'Leave', $leaveId,
                "$status leave for {$app['first_name']} {$app['last_name']}");
            if ($app['user_id']) {
                notify((int)$app['user_id'], "Leave $status",
                    'Your request from ' . date('j M', strtotime($app['start_date'])) . ' was ' . strtolower($status) . '.',
                    'modules/leave/index.php');
            }
            set_flash('success', "Request $status.");
        } else {
            set_flash('error', 'That request has already been reviewed.');
        }
    }
    redirect('modules/leave/index.php' . ($filter ? '?status=' . urlencode($filter) : ''));
}

/* ------------------------------------------------------------------
   Listing
------------------------------------------------------------------ */
$sql = "SELECT l.*, t.type_name, t.is_paid, e.first_name, e.last_name, e.emp_number
        FROM leave_applications l
        JOIN leave_types t ON t.leave_type_id = l.leave_type_id
        JOIN employees   e ON e.employee_id   = l.employee_id
        WHERE 1 = 1";
$args = [];

if (!$isOfficer) {
    $sql .= ' AND l.employee_id = ?';
    $args[] = current_user()['employee_id'] ?? 0;
} elseif (has_role('manager')) {
    $sql .= ' AND (e.manager_id = ? OR l.employee_id = ?)';
    $args[] = current_user()['employee_id'];
    $args[] = current_user()['employee_id'];
}
if ($filter !== '') { $sql .= ' AND l.status = ?'; $args[] = $filter; }
$sql .= ' ORDER BY FIELD(l.status, "Pending") DESC, l.applied_on DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$applications = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<?php if ($me): ?>
<section class="panel">
  <div class="panel-head"><h2>Apply for leave</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="apply">
    <div class="form-grid">
      <div class="field">
        <label for="leave_type_id">Leave type</label>
        <select id="leave_type_id" name="leave_type_id" required>
          <?php foreach ($leaveTypes as $t): ?>
            <option value="<?= $t['leave_type_id'] ?>">
              <?= e($t['type_name']) ?><?= $t['days_per_year'] ? ' — ' . (int)$t['days_per_year'] . ' days a year' : ' — unpaid' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="days_requested">Working days</label>
        <input type="number" id="days_requested" name="days_requested" min="1" value="1" required>
        <span class="hint">Counted automatically from the dates, weekends excluded.</span>
      </div>
      <div class="field">
        <label for="start_date">First day</label>
        <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="field">
        <label for="end_date">Last day</label>
        <input type="date" id="end_date" name="end_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="field full">
        <label for="reason">Reason</label>
        <textarea id="reason" name="reason" placeholder="Brief explanation for your manager"></textarea>
      </div>
    </div>
    <div class="form-actions"><button class="btn">Submit application</button></div>
  </form>
</section>
<?php else: ?>
<div class="panel empty">
  <h2>No leave form for this account</h2>
  <p>
    <?= $isOfficer
        ? 'This account is not linked to an employee record, so there is nothing to apply for. You can still review the requests below.'
        : 'This account is not linked to an employee record. Ask HR to link it before you can apply for leave.' ?>
  </p>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-head">
    <h2><?= $isOfficer ? 'Leave requests' : 'My applications' ?></h2>
    <div class="spacer"></div>
    <form method="get" data-autosubmit>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach (['Pending','Approved','Rejected','Cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $filter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <?php if ($isOfficer): ?><th>Employee</th><?php endif; ?>
          <th>Type</th><th>From</th><th>To</th><th class="num">Days</th>
          <th>Reason</th><th>Status</th><?php if ($isOfficer): ?><th>Decision</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$applications): ?>
        <tr><td colspan="8" class="muted">Nothing here yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($applications as $a): ?>
        <tr>
          <?php if ($isOfficer): ?>
            <td class="strong"><?= e($a['first_name'] . ' ' . $a['last_name']) ?><br>
                <span class="small muted"><?= e($a['emp_number']) ?></span></td>
          <?php endif; ?>
          <td><?= e($a['type_name']) ?><?= $a['is_paid'] ? '' : ' <span class="badge neutral">unpaid</span>' ?></td>
          <td class="nowrap"><?= fdate($a['start_date']) ?></td>
          <td class="nowrap"><?= fdate($a['end_date']) ?></td>
          <td class="num"><?= (int)$a['days_requested'] ?></td>
          <td class="small muted"><?= e($a['reason']) ?></td>
          <td><?= status_badge($a['status']) ?>
              <?php if ($a['review_comment']): ?><br><span class="small muted"><?= e($a['review_comment']) ?></span><?php endif; ?>
          </td>
          <?php if ($isOfficer): ?>
            <td>
              <?php if ($a['status'] === 'Pending'): ?>
                <form method="post" style="display:flex;gap:.35rem;align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="leave_id" value="<?= $a['leave_id'] ?>">
                  <input type="text" name="review_comment" placeholder="Comment" style="width:120px" class="small">
                  <button class="btn small" name="action" value="approve">Approve</button>
                  <button class="btn danger small" name="action" value="reject">Reject</button>
                </form>
              <?php else: ?>
                <span class="small muted"><?= $a['reviewed_on'] ? fdate($a['reviewed_on']) : '—' ?></span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include APP_ROOT . '/includes/footer.php'; ?>
