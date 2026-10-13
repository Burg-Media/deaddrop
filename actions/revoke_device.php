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
    redirect('admin_devices.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

if (!device_trust_tables_exist($pdo)) {
    $_SESSION['device_admin_error'] = 'Trusted device database-migratie ontbreekt.';
    redirect('admin_devices.php');
}

$deviceRef = trim((string) ($_POST['device_ref'] ?? ''));
$confirmed = (string) ($_POST['confirm_revoke'] ?? '') === '1';

if ($deviceRef === '' || !$confirmed) {
    $_SESSION['device_admin_error'] = 'Bevestig welk device je wilt revoken.';
    redirect('admin_devices.php');
}

$stmt = $pdo->prepare("
    SELECT id, label, status
    FROM trusted_devices
    WHERE public_id = ?
    LIMIT 1
");
$stmt->execute([$deviceRef]);
$device = $stmt->fetch();

if (!$device || $device['status'] === 'revoked') {
    $_SESSION['device_admin_error'] = 'Device niet gevonden of al revoked.';
    redirect('admin_devices.php');
}

$currentDevice = current_trusted_device($pdo);

$update = $pdo->prepare("
    UPDATE trusted_devices
    SET status = 'revoked',
        revoked_at = NOW()
    WHERE id = ?
    LIMIT 1
");
$update->execute([(int) $device['id']]);

if ($currentDevice && hash_equals($currentDevice['public_id'], $deviceRef)) {
    clear_trusted_device_cookie();
}

$_SESSION['device_admin_notice'] = 'Device revoked: ' . $device['label'];

redirect('admin_devices.php');
