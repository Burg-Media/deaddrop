<?php
declare(strict_types=1);

/**
 * Het eigen sleutelrecord, zodat de browser met de private pass de eigen
 * private keys kan openen (ontsleutelen, ondertekenen, pass wijzigen).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/keys.php';

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    json_response(['error' => 'Niet ingelogd.'], 401);
}

$record = load_key_record($pdo, (int) $currentUser['id']);

if (!$record) {
    json_response(['error' => 'Nog geen sleutels.'], 404);
}

json_response([
    'user_public_id' => $currentUser['public_id'],
    'username' => $currentUser['username'],
    'record' => $record,
    'kdf_min_iterations' => KDF_MIN_ITERATIONS,
]);
