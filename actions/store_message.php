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

$deaddropRef = $_POST['deaddrop_ref'] ?? '';
$recipientRef = $_POST['recipient_ref'] ?? '';
$source = $_POST['source'] ?? '';

$encryptedPayload = trim($_POST['encrypted_payload'] ?? '');
$iv = trim($_POST['iv'] ?? '');
$salt = trim($_POST['salt'] ?? '');

$expiryMinutes = (int) ($_POST['expiry_minutes'] ?? DEFAULT_EXPIRY_MINUTES);

$failCreate = static function (string $message) use ($source, $deaddropRef): never {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = $message;
        redirect('terminal.php');
    }

    $_SESSION['message_create_error'] = $message;
    redirect('message_create.php?drop=' . urlencode((string) $deaddropRef));
};

/**
 * Limieten: een lid kan geen onbeperkt grote of onbeperkt veel berichten wegzetten.
 * iv is 12 bytes, de versleutelde berichtsleutel past ruim in 1024 tekens base64.
 */
if (
    ($encryptedPayload !== '' && !is_strict_base64($encryptedPayload, MAX_PAYLOAD_LENGTH)) ||
    ($iv !== '' && !is_strict_base64($iv, 24)) ||
    ($salt !== '' && !is_strict_base64($salt, 1024))
) {
    $failCreate('Bericht aanmaken mislukt: versleutelde data is ongeldig of te groot.');
}

$lockedFor = throttle_locked_for($pdo, 'message', (string) $currentUser['id']);

if ($lockedFor > 0) {
    $failCreate('Je verstuurt te veel berichten achter elkaar. Probeer het over ' . throttle_wait_text($lockedFor) . ' opnieuw.');
}

if (
    $deaddropRef === '' ||
    $recipientRef === '' ||
    $encryptedPayload === '' ||
    $iv === '' ||
    $salt === ''
) {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Bericht aanmaken mislukt: ontbrekende encrypted velden.';
        redirect('terminal.php');
    }

    $_SESSION['message_create_error'] = 'Vul alle velden in.';
    redirect('message_create.php?drop=' . urlencode($deaddropRef));
}

/**
 * Check of verzender lid is van deze Deaddrop.
 */
$stmt = $pdo->prepare("
    SELECT 
        d.id,
        d.public_id,
        d.max_expiry_minutes
    FROM deaddrops d
    INNER JOIN deaddrop_members dm ON dm.deaddrop_id = d.id
    WHERE d.public_id = ?
      AND dm.user_id = ?
      AND dm.removed_at IS NULL
    LIMIT 1
");
$stmt->execute([$deaddropRef, (int) $currentUser['id']]);
$deaddrop = $stmt->fetch();

if (!$deaddrop) {
    http_response_code(403);
    exit('Geen toegang tot deze Deaddrop.');
}

/**
 * Expiry begrenzen.
 */
$maxExpiry = (int) $deaddrop['max_expiry_minutes'];

if ($expiryMinutes <= 0 || $expiryMinutes > $maxExpiry) {
    $expiryMinutes = DEFAULT_EXPIRY_MINUTES;
}

/**
 * Check of ontvanger lid is van dezelfde Deaddrop.
 */
$activeUserFilter = db_column_exists($pdo, 'users', 'deleted_at') ? 'AND u.deleted_at IS NULL' : '';

$recipientStmt = $pdo->prepare("
    SELECT 
        u.id,
        u.public_id,
        u.username
    FROM users u
    INNER JOIN deaddrop_members dm ON dm.user_id = u.id
    INNER JOIN user_keys uk ON uk.user_id = u.id
    WHERE u.public_id = ?
      AND dm.deaddrop_id = ?
      AND dm.removed_at IS NULL
      AND u.id != ?
      {$activeUserFilter}
    LIMIT 1
");
$recipientStmt->execute([
    $recipientRef,
    (int) $deaddrop['id'],
    (int) $currentUser['id']
]);
$recipient = $recipientStmt->fetch();

if (!$recipient) {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = 'Ongeldige ontvanger of ontvanger heeft nog geen key setup.';
        redirect('terminal.php');
    }

    $_SESSION['message_create_error'] = 'Ongeldige ontvanger of ontvanger heeft nog geen key setup.';
    redirect('message_create.php?drop=' . urlencode($deaddropRef));
}

/**
 * Aanmaaktijd afgerond op 5 minuten, ook in created_at: het exacte verzendmoment
 * wordt nergens bewaard.
 */
$createdBucket = time_bucket();

/**
 * De server ontvangt alleen client-side encrypted data.
 * Geen plaintext bericht en geen private pass.
 */
$publicId = generate_public_id();

$insert = $pdo->prepare("
    INSERT INTO messages (
        public_id,
        deaddrop_id,
        recipient_user_id,
        encrypted_payload,
        iv,
        salt,
        status,
        created_bucket,
        expires_at,
        created_at
    ) VALUES (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        'unopened',
        ?,
        DATE_ADD(NOW(), INTERVAL ? MINUTE),
        ?
    )
");

$insert->execute([
    $publicId,
    (int) $deaddrop['id'],
    (int) $recipient['id'],
    $encryptedPayload,
    $iv,
    $salt,
    $createdBucket,
    $expiryMinutes,
    $createdBucket,
]);

throttle_hit($pdo, 'message', (string) $currentUser['id'], 30, 600);

if ($source === 'terminal') {
    $_SESSION['terminal_active_channel'] = $deaddropRef;
    $_SESSION['terminal_notice'] = 'Encrypted drop stored for ' . $recipient['username'];
    redirect('terminal.php');
}

redirect('message_created.php?drop=' . urlencode($deaddropRef));
