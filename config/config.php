<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'paypro_hrms');
define('DB_USER', 'root');
define('DB_PASS', '');          


define('APP_NAME', 'PayPro');
define('APP_LONG_NAME', 'Employee Performance & Payroll Management System');
define('APP_ROOT', dirname(__DIR__));


$docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$appPath  = str_replace('\\', '/', APP_ROOT);
$basePath = ($docRoot && strpos($appPath, $docRoot) === 0)
          ? substr($appPath, strlen($docRoot))
          : '';
define('BASE_URL', rtrim($basePath, '/'));

date_default_timezone_set('Africa/Harare');


if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}


try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<p style="font:16px system-ui;padding:2rem">Database connection failed: '
        . htmlspecialchars($e->getMessage())
        . '<br>Check the DB_* values in config/config.php and that MySQL is running.</p>');
}

require_once APP_ROOT . '/includes/functions.php';
