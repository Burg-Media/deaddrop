<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

require_admin($currentUser);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin_users.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

if (!db_column_exists($pdo, 'users', 'deleted_at') || !db_column_exists($pdo, 'users', 'deleted_by_user_id')) {
    $_SESSION['user_delete_error'] = 'Database-migratie voor soft-delete ontbreekt.';
    redirect('admin_users.php');
}

$userRef = trim((string) ($_POST['user_ref'] ?? ''));
$confirmed = (string) ($_POST['confirm_delete'] ?? '') === '1';

if ($userRef === '' || !$confirmed) {
    $_SESSION['user_delete_error'] = 'Bevestig eerst dat je deze user wilt deactiveren.';
    redirect('admin_users.php');
}

$stmt = $pdo->prepare("
    SELECT id, public_id, username, role, deleted_at
    FROM users
    WHERE public_id = ?
    LIMIT 1
");
$stmt->execute([$userRef]);
$targetUser = $stmt->fetch();

if (!$targetUser || $targetUser['deleted_at'] !== null) {
    $_SESSION['user_delete_error'] = 'User niet gevonden of al gedeactiveerd.';
    redirect('admin_users.php');
}

if ((int) $targetUser['id'] === (int) $currentUser['id']) {
    $_SESSION['user_delete_error'] = 'Je kunt jezelf niet deactiveren.';
    redirect('admin_users.php');
}

if (is_admin($targetUser)) {
    $adminCountStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM users
        WHERE role IN ('admin', 'superadmin')
          AND deleted_at IS NULL
    ");
    $activeAdminCount = (int) ($adminCountStmt->fetch()['total'] ?? 0);

    if ($activeAdminCount <= 1) {
        $_SESSION['user_delete_error'] = 'Je kunt de laatste actieve admin niet deactiveren.';
        redirect('admin_users.php');
    }
}

if (is_superadmin($targetUser)) {
    $superadminCountStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM users
        WHERE role = 'superadmin'
          AND deleted_at IS NULL
    ");
    $activeSuperadminCount = (int) ($superadminCountStmt->fetch()['total'] ?? 0);

    if ($activeSuperadminCount <= 1) {
        $_SESSION['user_delete_error'] = 'Je kunt de laatste actieve superadmin niet deactiveren.';
        redirect('admin_users.php');
    }
}

$update = $pdo->prepare("
    UPDATE users
    SET deleted_at = NOW(),
        deleted_by_user_id = ?
    WHERE id = ?
      AND deleted_at IS NULL
    LIMIT 1
");
$update->execute([
    (int) $currentUser['id'],
    (int) $targetUser['id'],
]);

$_SESSION['user_delete_notice'] = 'User gedeactiveerd: ' . $targetUser['username'];

redirect('admin_users.php');
