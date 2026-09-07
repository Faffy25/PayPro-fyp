<?php
/**
 * Streams the Microsoft Excel attendance register for a given day.
 * Rebuilt from the attendance table on every request so it is always current.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_role(['admin', 'hr']);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$path = attendance_register_build($date);
if (!is_file($path)) {
    http_response_code(500);
    exit('Could not build the attendance register.');
}

log_action('EXPORT', 'Attendance', null, 'Downloaded the attendance register for ' . $date);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="attendance-' . $date . '.xls"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
