<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin','hr','manager']);

$report   = $_GET['report'] ?? 'payroll';
$periodId = (int)($_GET['period'] ?? 0);
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));
$apprId   = (int)($_GET['appraisal_period'] ?? 0);

switch ($report) {
    case 'performance':
        $header = ['Employee number','Name','Department','Score','Rating','Bonus %','Status'];
        $stmt = $pdo->prepare(
            "SELECT e.emp_number, CONCAT(e.first_name,' ',e.last_name) AS name, d.dept_name,
                    a.total_score, a.rating, a.bonus_percentage, a.status
             FROM performance_appraisals a
             JOIN employees e ON e.employee_id = a.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE a.period_id = ? ORDER BY a.total_score DESC"
        );
        $stmt->execute([$apprId]);
        break;

    case 'attendance':
        $header = ['Employee number','Name','Present','Late','Absent','On leave','Hours','Overtime'];
        $stmt = $pdo->prepare(
            "SELECT e.emp_number, CONCAT(e.first_name,' ',e.last_name) AS name,
                    SUM(a.status='Present') AS present, SUM(a.status='Late') AS late,
                    SUM(a.status='Absent') AS absent, SUM(a.status='On Leave') AS on_leave,
                    COALESCE(SUM(a.hours_worked),0) AS hours, COALESCE(SUM(a.overtime_hours),0) AS overtime
             FROM employees e
             LEFT JOIN attendance a ON a.employee_id = e.employee_id
                  AND MONTH(a.work_date) = ? AND YEAR(a.work_date) = ?
             WHERE e.status = 'Active' GROUP BY e.employee_id ORDER BY e.emp_number"
        );
        $stmt->execute([$month, $year]);
        break;

    case 'statutory':
        $header = ['Employee number','Name','National ID','Tax number','NSSA number','Gross','PAYE','AIDS levy','NSSA'];
        $stmt = $pdo->prepare(
            "SELECT e.emp_number, CONCAT(e.first_name,' ',e.last_name) AS name, e.national_id,
                    e.tax_number, e.nssa_number, s.gross_pay, s.paye, s.aids_levy, s.nssa
             FROM payslips s JOIN employees e ON e.employee_id = s.employee_id
             WHERE s.payroll_period_id = ? ORDER BY e.emp_number"
        );
        $stmt->execute([$periodId]);
        break;

    default:
        $report = 'payroll';
        $header = ['Employee number','Name','Department','Basic','Bonus','Gross','PAYE','AIDS levy','NSSA','Net'];
        $stmt = $pdo->prepare(
            "SELECT e.emp_number, CONCAT(e.first_name,' ',e.last_name) AS name, d.dept_name,
                    s.basic_salary, s.performance_bonus, s.gross_pay, s.paye, s.aids_levy, s.nssa, s.net_pay
             FROM payslips s
             JOIN employees e ON e.employee_id = s.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE s.payroll_period_id = ? ORDER BY e.emp_number"
        );
        $stmt->execute([$periodId]);
}

$filename = $report . '-report-' . date('Y-m-d') . '.csv';
log_action('EXPORT', 'Reports', null, "Exported $report report as CSV");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, $header);
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    fputcsv($out, $row);
}
fclose($out);
