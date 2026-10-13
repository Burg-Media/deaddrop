<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$terminalPage = $terminalPage ?? false;
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <?= stylesheet_tag('assets/css/style.css') ?>
</head>
<body class="<?= $terminalPage ? 'terminal-page' : '' ?>">
<?php if (!$terminalPage): ?>
<header class="site-header">
    <div class="container">
        <strong><?= e(APP_NAME) ?></strong>

        <?php if (is_logged_in()): ?>
            <nav>
                <a href="<?= APP_URL ?>/dashboard.php">Dashboard</a>
                <a href="<?= APP_URL ?>/terminal.php">Terminal</a>
                <a href="<?= APP_URL ?>/profile.php">Sleutels</a>
                <?php if (isset($currentUser) && is_admin($currentUser)): ?>
                    <a href="<?= APP_URL ?>/admin_channels.php">Channels</a>
                    <a href="<?= APP_URL ?>/admin_users.php">Users</a>
                    <a href="<?= APP_URL ?>/admin_devices.php">Devices</a>
                <?php endif; ?>
                <?php if (isset($currentUser) && is_superadmin($currentUser)): ?>
                    <a href="<?= APP_URL ?>/node_admin.php">Node</a>
                    <a href="<?= APP_URL ?>/node_identity.php">Identity</a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/logout.php">Uitloggen</a>
            </nav>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>

<main class="<?= $terminalPage ? 'terminal' : 'container' ?>">
