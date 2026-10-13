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
    redirect('admin_channels.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$source = $_POST['source'] ?? '';
$name = trim($_POST['name'] ?? '');
$defaultExpiryMinutes = (int) ($_POST['default_expiry_minutes'] ?? DEFAULT_EXPIRY_MINUTES);
$maxExpiryMinutes = (int) ($_POST['max_expiry_minutes'] ?? MAX_EXPIRY_MINUTES);

if ($name === '' || strlen($name) < 3 || strlen($name) > 80) {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Channelnaam ongeldig. Kies 3 tot 80 tekens.';
        redirect('terminal.php');
    }

    $_SESSION['channel_create_error'] = 'Kies een channelnaam tussen 3 en 80 tekens.';
    redirect('admin_channels.php');
}

if ($defaultExpiryMinutes <= 0 || $defaultExpiryMinutes > MAX_EXPIRY_MINUTES) {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Default expiry is ongeldig.';
        redirect('terminal.php');
    }

    $_SESSION['channel_create_error'] = 'Default expiry is ongeldig.';
    redirect('admin_channels.php');
}

if ($maxExpiryMinutes <= 0 || $maxExpiryMinutes > MAX_EXPIRY_MINUTES || $maxExpiryMinutes < $defaultExpiryMinutes) {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Max expiry moet geldig zijn en minimaal gelijk aan default expiry.';
        redirect('terminal.php');
    }

    $_SESSION['channel_create_error'] = 'Max expiry moet geldig zijn en minimaal gelijk aan default expiry.';
    redirect('admin_channels.php');
}

$publicId = generate_public_id();

try {
    $pdo->beginTransaction();

    $insertDrop = $pdo->prepare("
        INSERT INTO deaddrops (
            public_id,
            name,
            owner_user_id,
            default_expiry_minutes,
            max_expiry_minutes,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $insertDrop->execute([
        $publicId,
        $name,
        (int) $currentUser['id'],
        $defaultExpiryMinutes,
        $maxExpiryMinutes,
    ]);

    $deaddropId = (int) $pdo->lastInsertId();

    $insertMember = $pdo->prepare("
        INSERT INTO deaddrop_members (
            deaddrop_id,
            user_id,
            role,
            created_at
        ) VALUES (
            ?,
            ?,
            'owner',
            NOW()
        )
    ");

    $insertMember->execute([
        $deaddropId,
        (int) $currentUser['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Channel aanmaken is mislukt.';
        redirect('terminal.php');
    }

    $_SESSION['channel_create_error'] = 'Channel aanmaken is mislukt.';
    redirect('admin_channels.php');
}

if ($source === 'terminal') {
    $_SESSION['terminal_notice'] = 'Channel aangemaakt: ' . $name;
    $_SESSION['terminal_active_channel'] = $publicId;
    redirect('terminal.php');
}

redirect('deaddrop.php?ref=' . urlencode($publicId));
