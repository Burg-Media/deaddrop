<?php
declare(strict_types=1);

/**
 * Een mislukte ontsleutelpoging (verkeerde private pass). Na MAX_DECRYPT_ATTEMPTS
 * gaat het bericht op slot en is de ciphertext weg. De teller staat serverside,
 * dus een herladen pagina begint niet opnieuw.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/messages.php';

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    json_response(['error' => 'Niet ingelogd.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Alleen POST.'], 405);
}

$data = request_data();
verify_csrf_token($data['csrf_token'] ?? null);

$message = find_recipient_message($pdo, (string) ($data['message_ref'] ?? ''), (int) $currentUser['id']);

if (!$message) {
    json_response(['error' => 'Bericht niet gevonden.'], 404);
}

if ($message['status'] !== 'reading') {
    json_response(['status' => $message['status'], 'remaining' => 0]);
}

$stmt = $pdo->prepare('UPDATE messages SET failed_attempts = failed_attempts + 1 WHERE id = ? LIMIT 1');
$stmt->execute([(int) $message['id']]);

$attempts = (int) $message['failed_attempts'] + 1;

if ($attempts >= MAX_DECRYPT_ATTEMPTS) {
    wipe_message($pdo, (int) $message['id'], 'locked');
    json_response(['status' => 'locked', 'remaining' => 0]);
}

json_response(['status' => 'reading', 'remaining' => MAX_DECRYPT_ATTEMPTS - $attempts]);
