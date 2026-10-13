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

$messageRef = $_POST['message_ref'] ?? '';

if ($messageRef === '') {
    redirect('dashboard.php');
}

/**
 * Bericht ophalen en checken of dit bericht voor de ingelogde user is.
 */
$stmt = $pdo->prepare("
    SELECT 
        m.id,
        m.public_id,
        m.recipient_user_id,
        m.status,
        d.public_id AS deaddrop_public_id
    FROM messages m
    INNER JOIN deaddrops d ON d.id = m.deaddrop_id
    WHERE m.public_id = ?
      AND m.recipient_user_id = ?
    LIMIT 1
");
$stmt->execute([$messageRef, (int) $currentUser['id']]);
$message = $stmt->fetch();

if (!$message) {
    http_response_code(404);
    exit('Bericht niet gevonden of geen toegang.');
}

/**
 * Alleen actieve berichten vernietigen.
 */
if (in_array($message['status'], ['unopened', 'reading'], true)) {
    $destroy = $pdo->prepare("
        UPDATE messages
        SET 
            encrypted_payload = NULL,
            iv = NULL,
            salt = NULL,
            status = 'destroyed',
            opened_at = NOW(),
            destroyed_at = NOW()
        WHERE id = ?
          AND recipient_user_id = ?
        LIMIT 1
    ");

    $destroy->execute([
        (int) $message['id'],
        (int) $currentUser['id']
    ]);
}

redirect('deaddrop.php?ref=' . urlencode($message['deaddrop_public_id']));