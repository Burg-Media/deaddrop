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

$label = trim((string) ($_POST['label'] ?? ''));
$userRef = trim((string) ($_POST['user_ref'] ?? ''));
$expiresHours = (int) ($_POST['expires_hours'] ?? 24);
$source = (string) ($_POST['source'] ?? '');
$returnPath = $source === 'terminal' ? 'terminal.php' : 'admin_devices.php';

$setError = static function (string $message) use ($source, $returnPath): never {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = $message;
        redirect($returnPath);
    }

    $_SESSION['device_admin_error'] = $message;
    redirect($returnPath);
};

if ($label === '' || strlen($label) < 3 || strlen($label) > 120) {
    $setError('Gebruik een device label van 3 tot 120 tekens.');
}

if (!in_array($expiresHours, [1, 8, 24, 72], true)) {
    $expiresHours = 24;
}

$userId = null;

// Een device zonder vaste user, of een device voor een beheeraccount, opent de deur
// naar beheerrechten. Alleen een superadmin mag zo'n code maken.
if ($userRef === '' && !is_superadmin($currentUser)) {
    $setError('Alleen een superadmin maakt een code zonder vaste user. Kies de user voor dit device.');
}

if ($userRef !== '') {
    $deletedFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'AND deleted_at IS NULL' : '';
    $userStmt = $pdo->prepare("
        SELECT id, role
        FROM users
        WHERE public_id = ?
          {$deletedFilter}
        LIMIT 1
    ");
    $userStmt->execute([$userRef]);
    $targetUser = $userStmt->fetch();

    if (!$targetUser) {
        $setError('User voor dit device is niet gevonden.');
    }

    if (is_admin($targetUser) && !is_superadmin($currentUser)) {
        $setError('Alleen een superadmin maakt een activatiecode voor een admin- of superadmin-account.');
    }

    $userId = (int) $targetUser['id'];
}

$activationCode = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
$activationCode = strtoupper($activationCode);

try {
    $insert = $pdo->prepare("
        INSERT INTO device_activation_codes (
            public_id,
            user_id,
            label,
            code_hash,
            expires_at,
            created_by_user_id,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            DATE_ADD(NOW(), INTERVAL ? HOUR),
            ?,
            NOW()
        )
    ");

    $insert->execute([
        generate_public_id(),
        $userId,
        $label,
        activation_code_hash($activationCode),
        $expiresHours,
        (int) $currentUser['id'],
    ]);
} catch (Throwable $e) {
    error_log('Device activation code create failed: ' . $e->getMessage());
    $setError('Activatiecode aanmaken is mislukt.');
}

if ($source === 'terminal') {
    $_SESSION['terminal_notice'] = 'Device activation code: ' . $activationCode . ' voor ' . $label;
    redirect($returnPath);
}

$_SESSION['device_activation_code'] = $activationCode;
$_SESSION['device_admin_notice'] = 'Activatiecode aangemaakt voor ' . $label . '. Toon deze code maar eenmalig.';

redirect($returnPath);
