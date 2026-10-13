<?php
declare(strict_types=1);

/**
 * Legt vast welke fingerprint deze gebruiker van een contact heeft gezien,
 * en of hij die buiten Deaddrop om heeft gecontroleerd.
 * Alleen op uitdrukkelijk verzoek van de browser; de server verandert een pin nooit zelf.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    json_response(['error' => 'Niet ingelogd.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Alleen POST.'], 405);
}

$data = request_data();
verify_csrf_token($data['csrf_token'] ?? null);

$subjectRef = trim((string) ($data['subject_ref'] ?? ''));
$fingerprint = strtolower(trim((string) ($data['fingerprint'] ?? '')));
$verified = !empty($data['verified']);

if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
    json_response(['error' => 'Ongeldige fingerprint.'], 400);
}

$subjectStmt = $pdo->prepare('SELECT id FROM users WHERE public_id = ? LIMIT 1');
$subjectStmt->execute([$subjectRef]);
$subject = $subjectStmt->fetch();

if (!$subject || (int) $subject['id'] === (int) $currentUser['id']) {
    json_response(['error' => 'Contact niet gevonden.'], 404);
}

$existingStmt = $pdo->prepare('SELECT id, fingerprint, verified_at FROM known_keys WHERE observer_user_id = ? AND subject_user_id = ? LIMIT 1');
$existingStmt->execute([(int) $currentUser['id'], (int) $subject['id']]);
$existing = $existingStmt->fetch();

if (!$existing) {
    $insert = $pdo->prepare('INSERT INTO known_keys (observer_user_id, subject_user_id, fingerprint, first_seen_at, verified_at) VALUES (?, ?, ?, CURDATE(), IF(?, CURDATE(), NULL))');
    $insert->execute([(int) $currentUser['id'], (int) $subject['id'], $fingerprint, $verified ? 1 : 0]);
} elseif (!hash_equals($existing['fingerprint'], $fingerprint)) {
    $update = $pdo->prepare('UPDATE known_keys SET fingerprint = ?, first_seen_at = CURDATE(), verified_at = IF(?, CURDATE(), NULL) WHERE id = ?');
    $update->execute([$fingerprint, $verified ? 1 : 0, (int) $existing['id']]);
} elseif ($verified && $existing['verified_at'] === null) {
    $update = $pdo->prepare('UPDATE known_keys SET verified_at = CURDATE() WHERE id = ?');
    $update->execute([(int) $existing['id']]);
}

json_response(['status' => 'pinned', 'verified' => $verified || ($existing && $existing['verified_at'] !== null && hash_equals($existing['fingerprint'], $fingerprint))]);
