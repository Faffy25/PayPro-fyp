<?php
if (!defined('APP_ROOT')) { require_once dirname(__DIR__) . '/config/config.php'; }
$u          = current_user();
$pageTitle  = $pageTitle ?? 'Dashboard';
$initials   = $u ? strtoupper(substr($u['username'], 0, 2)) : '??';
$unread     = $u ? unread_count() : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="layout">
<?php include APP_ROOT . '/includes/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <button class="menu-btn" id="menuToggle" aria-label="Open menu">&#9776;</button>
      <h1 class="page-title"><?= e($pageTitle) ?></h1>
      <div class="spacer"></div>
      <a class="bell" href="<?= url('modules/admin/notifications.php') ?>" title="Notifications">
        &#128276;<?php if ($unread): ?><span class="dot"><?= $unread ?></span><?php endif; ?>
      </a>
      <div class="who">
        <strong><?= e($u['full_name'] ?? $u['username']) ?></strong>
        <span><?= e(ucfirst($u['role_name'])) ?></span>
      </div>
      <div class="avatar"><?= e($initials) ?></div>
      <a class="btn secondary small" href="<?= url('logout.php') ?>">Sign out</a>
    </header>
    <main class="content">
<?php foreach (get_flashes() as $f): ?>
      <div class="alert <?= e($f['type']) ?>"><?= $f['message'] ?></div>
<?php endforeach; ?>
