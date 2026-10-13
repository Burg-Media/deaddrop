<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$source = (string) ($_POST['source'] ?? '');
$deaddropRef = trim((string) ($_POST['deaddrop_ref'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$defaultExpiryMinutes = (int) ($_POST['default_expiry_minutes'] ?? DEFAULT_EXPIRY_MINUTES);
$maxExpiryMinutes = (int) ($_POST['max_expiry_minutes'] ?? MAX_EXPIRY_MINUTES);
$returnPath = $deaddropRef !== '' ? 'deaddrop.php?ref=' . urlencode($deaddropRef) : 'dashboard.php';

$setError = static function (string $message) use ($source, $returnPath): never {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = $message;
        redirect('terminal.php');
    }

    $_SESSION['channel_manage_error'] = $message;
    redirect($returnPath);
};

if ($deaddropRef === '') {
    $setError('Channel ontbreekt.');
}

if ($name === '' || strlen($name) < 3 || strlen($name) > 80) {
    $setError('Kies een channelnaam tussen 3 en 80 tekens.');
}

if ($defaultExpiryMinutes <= 0 || $defaultExpiryMinutes > MAX_EXPIRY_MINUTES) {
    $setError('Default expiry is ongeldig.');
}

if ($maxExpiryMinutes <= 0 || $maxExpiryMinutes > MAX_EXPIRY_MINUTES || $maxExpiryMinutes < $defaultExpiryMinutes) {
    $setError('Max expiry moet geldig zijn en minimaal gelijk aan default expiry.');
}

$stmt = $pdo->prepare("
    SELECT
        d.id,
        d.name,
        dm.role AS current_member_role
    FROM deaddrops d
    LEFT JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
        AND dm.user_id = ?
        AND dm.removed_at IS NULL
    WHERE d.public_id = ?
    LIMIT 1
");
$stmt->execute([(int) $currentUser['id'], $deaddropRef]);
$deaddrop = $stmt->fetch();

if (!$deaddrop) {
    $setError('Channel niet gevonden.');
}

$canManage = is_admin($currentUser) || $deaddrop['current_member_role'] === 'owner';

if (!$canManage) {
    $setError('Je hebt geen rechten om dit channel te beheren.');
}

try {
    $update = $pdo->prepare("
        UPDATE deaddrops
        SET name = ?,
            default_expiry_minutes = ?,
            max_expiry_minutes = ?
        WHERE id = ?
        LIMIT 1
    ");

    $update->execute([
        $name,
        $defaultExpiryMinutes,
        $maxExpiryMinutes,
        (int) $deaddrop['id'],
    ]);
} catch (Throwable $e) {
    error_log('Channel update failed: ' . $e->getMessage());
    $setError('Channel bijwerken is mislukt.');
}

if ($source === 'terminal') {
    $_SESSION['terminal_notice'] = 'Channel bijgewerkt: ' . $name;
    $_SESSION['terminal_active_channel'] = $deaddropRef;
    redirect('terminal.php');
}

$_SESSION['channel_manage_notice'] = 'Channel bijgewerkt: ' . $name;

redirect($returnPath);
