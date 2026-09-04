<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    log_action('LOGOUT', 'Authentication', current_user()['user_id'], 'Signed out');
}
$_SESSION = [];
session_destroy();
session_start();
set_flash('success', 'You are signed out.');
redirect('login.php');
