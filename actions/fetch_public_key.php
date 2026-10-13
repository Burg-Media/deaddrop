<?php
declare(strict_types=1);

/**
 * Public keys van een contact binnen een gedeeld channel, plus de fingerprint
 * die de opvrager eerder van dit contact zag (known_keys).
 *
 * De fingerprint zelf rekent de browser uit over de ontvangen public keys:
 * de server levert alleen sleutels, de browser beslist of ze kloppen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    json_response(['error' => 'Niet ingelogd.'], 401);
}

$deaddropRef = trim((string) ($_GET['deaddrop_ref'] ?? ''));
$userRef = trim((string) ($_GET['user_ref'] ?? $_GET['recipient_ref'] ?? ''));

if ($deaddropRef === '' || $userRef === '') {
    json_response(['error' => 'Ontbrekende parameters.'], 400);
}

$stmt = $pdo->prepare("
    SELECT d.id
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
    json_response(['error' => 'Geen toegang tot deze Deaddrop.'], 403);
}

/**
 * Ook oud-leden: een ontvangen bericht moet te verifiëren blijven als de
 * afzender inmiddels uit het channel is.
 */
$contactStmt = $pdo->prepare("
    SELECT
        u.id,
        u.public_id,
        u.username,
        uk.public_key,
        uk.signing_public_key,
        dm.removed_at
    FROM users u
    INNER JOIN deaddrop_members dm ON dm.user_id = u.id
    INNER JOIN user_keys uk ON uk.user_id = u.id
    WHERE u.public_id = ?
      AND dm.deaddrop_id = ?
    LIMIT 1
");
$contactStmt->execute([$userRef, (int) $deaddrop['id']]);
$contact = $contactStmt->fetch();

if (!$contact) {
    json_response(['error' => 'Deze user heeft nog geen public key of zit niet in dit channel.'], 404);
}

$known = null;

if (db_table_exists($pdo, 'known_keys')) {
    $knownStmt = $pdo->prepare('SELECT fingerprint, first_seen_at, verified_at FROM known_keys WHERE observer_user_id = ? AND subject_user_id = ? LIMIT 1');
    $knownStmt->execute([(int) $currentUser['id'], (int) $contact['id']]);
    $known = $knownStmt->fetch() ?: null;
}

json_response([
    'user_public_id' => $contact['public_id'],
    'username' => $contact['username'],
    'public_key' => $contact['public_key'],
    'signing_public_key' => $contact['signing_public_key'],
    'active_member' => $contact['removed_at'] === null,
    'known' => $known,
]);
