<?php
declare(strict_types=1);

/**
 * De browser meldt dat het bericht ontsleuteld is. Vanaf nu staat de
 * ciphertext nergens meer op de server.
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

if ($message['status'] === 'reading') {
    wipe_message($pdo, (int) $message['id'], 'opened');
}

json_response(['status' => 'opened']);
