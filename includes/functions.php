<?php
/**
 * Shared helpers used across every module.
 */

// ---------------------------------------------------------------
// Output / navigation
// ---------------------------------------------------------------
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Absolute URL for the current host — needed inside a QR code a phone will open. */
function abs_url(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

function money($amount): string {
    return number_format((float)$amount, 2);
}

function fdate($date, string $format = 'd M Y'): string {
    if (!$date || $date === '0000-00-00') return '&mdash;';
    return date($format, strtotime($date));
}

// ---------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------
function set_flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ---------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        die('Security check failed. Go back, reload the page and submit the form again.');
    }
}

// ---------------------------------------------------------------
// Authentication & authorisation
// ---------------------------------------------------------------
function is_logged_in(): bool {
    return !empty($_SESSION['user']['user_id']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function user_role(): string {
    return $_SESSION['user']['role_name'] ?? '';
}

/** True when the signed-in user holds any of the given roles. */
function has_role($roles): bool {
    $roles = (array)$roles;
    return in_array(user_role(), $roles, true);
}

function require_login(): void {
    if (!is_logged_in()) {
        set_flash('error', 'Sign in to continue.');
        redirect('login.php');
    }
}

/** Blocks the page unless the user holds one of the listed roles. */
function require_role($roles): void {
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        include APP_ROOT . '/includes/header.php';
        echo '<div class="panel empty"><h2>No access to this page</h2>'
           . '<p>Your role (' . e(user_role()) . ') cannot open this section. '
           . 'Ask an administrator if you need it.</p>'
           . '<a class="btn" href="' . url('index.php') . '">Back to dashboard</a></div>';
        include APP_ROOT . '/includes/footer.php';
        exit;
    }
}

// ---------------------------------------------------------------
// Audit trail — one reusable call from every write operation
// ---------------------------------------------------------------
function log_action(string $action, string $module, ?int $recordId = null, string $description = ''): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, username, action, module, record_id, description, ip_address)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $_SESSION['user']['user_id'] ?? null,
            $_SESSION['user']['username'] ?? 'guest',
            strtoupper($action),
            $module,
            $recordId,
            mb_substr($description, 0, 300),
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    } catch (PDOException $e) {
        // Auditing must never break the operation it is recording.
        error_log('Audit write failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------
function notify(int $userId, string $title, string $message = '', string $link = ''): void {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $title, $message, $link]);
}

/** Notifies every user holding one of the given roles. */
function notify_roles(array $roles, string $title, string $message = '', string $link = ''): void {
    global $pdo;
    $in   = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
                           WHERE r.role_name IN ($in) AND u.status = 'Active'");
    $stmt->execute($roles);
    foreach ($stmt->fetchAll() as $row) {
        notify((int)$row['user_id'], $title, $message, $link);
    }
}

function unread_count(): int {
    global $pdo;
    if (!is_logged_in()) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([current_user()['user_id']]);
    return (int)$stmt->fetchColumn();
}

// ---------------------------------------------------------------
// Settings
// ---------------------------------------------------------------
function setting(string $key, $default = null) {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/** Insert or update a single setting. */
function set_setting(string $key, string $value, string $description = ''): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, description) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value, $description]);
}

// ---------------------------------------------------------------
// QR attendance
// ---------------------------------------------------------------
/** Signing key for the attendance QR, created on first use. */
function attendance_qr_secret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $secret = (string)setting('attendance_qr_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        set_setting('attendance_qr_secret', $secret, 'Signing key for the attendance QR code');
    }
    return $secret;
}

/** Token embedded in the QR, valid only for the given date. */
function attendance_token(string $date): string {
    return substr(hash_hmac('sha256', $date, attendance_qr_secret()), 0, 16);
}

/**
 * Record a QR scan for an employee: first scan of the day clocks in,
 * the next one clocks out. Returns a result array for the confirmation screen.
 */
function mark_attendance_scan(array $emp): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()');
    $stmt->execute([$emp['employee_id']]);
    $row = $stmt->fetch();

    $who   = trim($emp['first_name'] . ' ' . $emp['last_name']);
    $stamp = date('H:i');
    $link  = 'modules/attendance/qr.php';

    if (!$row) {
        $status = (int)date('H') >= 9 ? 'Late' : 'Present';
        $pdo->prepare('INSERT INTO attendance (employee_id, work_date, time_in, status) VALUES (?, CURDATE(), CURTIME(), ?)')
            ->execute([$emp['employee_id'], $status]);
        log_action('CREATE', 'Attendance', (int)$pdo->lastInsertId(), 'QR clock-in at ' . $stamp);
        notify_roles(
            ['hr', 'admin'],
            ($status === 'Late' ? 'Late clock-in: ' : 'Clock-in: ') . $who,
            $who . ' (' . $emp['emp_number'] . ') clocked in at ' . $stamp
                . ($status === 'Late' ? ', recorded as late.' : '.'),
            $link
        );
        attendance_register_build(date('Y-m-d'));
        return [
            'type' => 'in', 'status' => $status,
            'time_in' => $stamp, 'time_out' => null,
            'message' => 'Clocked in at ' . $stamp . ($status === 'Late' ? ' — recorded as late.' : '.'),
        ];
    }

    if (!$row['time_out']) {
        $hours    = max(0, round((strtotime(date('H:i:s')) - strtotime($row['time_in'])) / 3600, 2));
        $standard = (float)setting('working_hours', 8);
        $overtime = max(0, round($hours - $standard, 2));
        $pdo->prepare('UPDATE attendance SET time_out = CURTIME(), hours_worked = ?, overtime_hours = ? WHERE attendance_id = ?')
            ->execute([$hours, $overtime, $row['attendance_id']]);
        log_action('UPDATE', 'Attendance', (int)$row['attendance_id'], 'QR clock-out after ' . $hours . ' hours');
        notify_roles(
            ['hr', 'admin'],
            'Clock-out: ' . $who,
            $who . ' (' . $emp['emp_number'] . ') clocked out at ' . $stamp . ' — ' . $hours . ' hours'
                . ($overtime ? ', including ' . $overtime . ' overtime.' : '.'),
            $link
        );
        attendance_register_build(date('Y-m-d'));
        return [
            'type' => 'out', 'status' => $row['status'],
            'time_in' => substr((string)$row['time_in'], 0, 5), 'time_out' => $stamp,
            'hours' => $hours, 'overtime' => $overtime,
            'message' => 'Clocked out at ' . $stamp . '. ' . $hours . ' hours recorded'
                       . ($overtime ? ', including ' . $overtime . ' overtime.' : '.'),
        ];
    }

    return [
        'type' => 'done', 'status' => $row['status'],
        'time_in' => substr((string)$row['time_in'], 0, 5), 'time_out' => substr((string)$row['time_out'], 0, 5),
        'message' => 'You have already clocked in and out today.',
    ];
}

/** Folder that holds the generated daily attendance workbooks. */
function attendance_register_dir(): string {
    $dir = APP_ROOT . '/storage/attendance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Full path of the Excel workbook for a given day (file may not exist yet). */
function attendance_register_path(string $date): string {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    return attendance_register_dir() . '/attendance-' . $date . '.xls';
}

/**
 * Write (or rewrite) the Microsoft Excel register of everyone who clocked
 * in on $date, straight from the attendance table. Returns the file path.
 *
 * The file is SpreadsheetML 2003 — a plain-XML workbook Excel opens
 * natively, so no PHP zip/spreadsheet extension is needed.
 */
function attendance_register_build(string $date): string {
    global $pdo;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT e.emp_number, CONCAT(e.first_name, ' ', e.last_name) AS name,
                COALESCE(d.dept_name, '-') AS dept,
                a.time_in, a.time_out, a.hours_worked, a.overtime_hours, a.status,
                COALESCE(a.remarks, '') AS remarks
         FROM attendance a
         JOIN employees e        ON e.employee_id = a.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         WHERE a.work_date = ?
         ORDER BY a.time_in IS NULL, a.time_in, e.emp_number"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();

    $header = ['Employee #', 'Name', 'Department', 'Clock in', 'Clock out', 'Hours', 'Overtime', 'Status', 'Remarks'];
    $company = setting('company_name', 'Company');
    $title   = $company . ' — Attendance register for ' . date('l, j F Y', strtotime($date));

    $cell = function ($v, string $type = 'String'): string {
        return '<Cell><Data ss:Type="' . $type . '">' . e($v) . '</Data></Cell>';
    };

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
          . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    $xml .= '<Styles>'
          . '<Style ss:ID="ttl"><Font ss:Bold="1" ss:Size="12"/></Style>'
          . '<Style ss:ID="hdr"><Font ss:Bold="1"/>'
          . '<Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/>'
          . '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>'
          . '</Style></Styles>' . "\n";
    $xml .= '<Worksheet ss:Name="Attendance"><Table>' . "\n";
    $xml .= '<Row><Cell ss:StyleID="ttl"><Data ss:Type="String">' . e($title) . '</Data></Cell></Row>' . "\n";
    $xml .= '<Row></Row>' . "\n";

    $xml .= '<Row>';
    foreach ($header as $h) {
        $xml .= '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . e($h) . '</Data></Cell>';
    }
    $xml .= '</Row>' . "\n";

    foreach ($rows as $r) {
        $xml .= '<Row>'
              . $cell($r['emp_number'])
              . $cell($r['name'])
              . $cell($r['dept'])
              . $cell(substr((string)$r['time_in'], 0, 5) ?: '-')
              . $cell(substr((string)$r['time_out'], 0, 5) ?: '-')
              . $cell((float)$r['hours_worked'], 'Number')
              . $cell((float)$r['overtime_hours'], 'Number')
              . $cell($r['status'])
              . $cell($r['remarks'])
              . '</Row>' . "\n";
    }

    if (!$rows) {
        $xml .= '<Row><Cell><Data ss:Type="String">No one has clocked in yet.</Data></Cell></Row>' . "\n";
    }

    $xml .= '</Table></Worksheet></Workbook>' . "\n";

    $path = attendance_register_path($date);
    file_put_contents($path, $xml, LOCK_EX);
    return $path;
}

// ---------------------------------------------------------------
// Payroll calculations
// ---------------------------------------------------------------
/**
 * PAYE from the tax_bands table using the standard
 * "taxable x rate - deduction" table method.
 */
function calculate_paye(float $taxableIncome): array {
    global $pdo;
    $stmt = $pdo->query('SELECT * FROM tax_bands ORDER BY min_amount ASC');
    $tax  = 0.0;
    foreach ($stmt->fetchAll() as $band) {
        $min = (float)$band['min_amount'];
        $max = $band['max_amount'] === null ? INF : (float)$band['max_amount'];
        if ($taxableIncome >= $min && $taxableIncome <= $max) {
            $tax = ($taxableIncome * (float)$band['rate']) - (float)$band['deduction'];
            break;
        }
    }
    $tax   = max(0, round($tax, 2));
    $levy  = round($tax * (float)setting('aids_levy_rate', 0.03), 2);
    return ['paye' => $tax, 'aids_levy' => $levy, 'total' => round($tax + $levy, 2)];
}

/** NSSA employee contribution, capped at the insurable earnings ceiling. */
function calculate_nssa(float $grossPay): float {
    $rate    = (float)setting('nssa_rate', 0.045);
    $ceiling = (float)setting('nssa_ceiling', 700);
    return round(min($grossPay, $ceiling) * $rate, 2);
}

/**
 * The performance-to-pay link: turns an appraisal score into a bonus
 * percentage using the bonus_rules table.
 */
function bonus_for_score(float $score): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT rating, bonus_percentage FROM bonus_rules
                           WHERE ? BETWEEN min_score AND max_score LIMIT 1');
    $stmt->execute([$score]);
    $rule = $stmt->fetch();
    return $rule ?: ['rating' => 'Unsatisfactory', 'bonus_percentage' => 0];
}

/** Most recent approved appraisal for an employee, or null. */
function latest_approved_appraisal(int $employeeId): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT a.*, p.name AS period_name FROM performance_appraisals a
         JOIN appraisal_periods p ON p.period_id = a.period_id
         WHERE a.employee_id = ? AND a.status = 'Approved'
         ORDER BY p.end_date DESC, a.appraisal_id DESC LIMIT 1"
    );
    $stmt->execute([$employeeId]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------
// Small view helpers
// ---------------------------------------------------------------
function status_badge(string $status): string {
    $map = [
        'Active' => 'ok', 'Approved' => 'ok', 'Present' => 'ok', 'Paid' => 'ok', 'Processed' => 'ok',
        'Pending' => 'warn', 'Draft' => 'warn', 'Submitted' => 'warn', 'Late' => 'warn', 'Half Day' => 'warn',
        'Rejected' => 'bad', 'Absent' => 'bad', 'Terminated' => 'bad', 'Disabled' => 'bad', 'Suspended' => 'bad',
    ];
    $class = $map[$status] ?? 'neutral';
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

function month_name(int $month): string {
    return date('F', mktime(0, 0, 0, $month, 1));
}

/** Employee row tied to the signed-in user (null for accounts with no staff record). */
function my_employee(): ?array {
    global $pdo;
    $id = current_user()['employee_id'] ?? null;
    if (!$id) return null;
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
