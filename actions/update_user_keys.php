<?php
declare(strict_types=1);

/**
 * Sleutelonderhoud vanuit de browser. Drie soorten:
 *
 *   upgrade  oude sleutels naar de huidige standaard (600.000 iteraties en een
 *            ondertekensleutel erbij). Het versleutelsleutelpaar blijft gelijk.
 *   rewrap   private pass wijzigen: zelfde sleutelparen, nieuw ingepakt.
 *            Fingerprints veranderen niet, contacten merken niets.
 *   replace  nieuw sleutelpaar (roteren) of een back-up terugzetten.
 *            De fingerprint verandert; contacten krijgen terecht een waarschuwing.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/keys.php';

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    json_response(['error' => 'Niet ingelogd.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Alleen POST.'], 405);
}

$data = request_data();
verify_csrf_token($data['csrf_token'] ?? null);

$mode = (string) ($data['mode'] ?? '');
$current = load_key_record($pdo, (int) $currentUser['id']);

if (!$current) {
    json_response(['error' => 'Nog geen sleutels.'], 404);
}

$record = validate_key_record(is_array($data['record'] ?? null) ? $data['record'] : []);

if ($record === null) {
    json_response(['error' => 'Sleuteldata ontbreekt of is ongeldig.'], 400);
}

switch ($mode) {
    case 'upgrade':
        // Versleutelsleutel moet gelijk blijven; een ondertekensleutel mag er alleen bij komen.
        if (!hash_equals($current['public_key'], $record['public_key'])) {
            json_response(['error' => 'Upgrade mag het sleutelpaar niet vervangen.'], 400);
        }

        if ($current['signing_public_key'] !== null && !hash_equals($current['signing_public_key'], $record['signing_public_key'])) {
            json_response(['error' => 'Upgrade mag de ondertekensleutel niet vervangen.'], 400);
        }
        break;

    case 'rewrap':
        if (
            !hash_equals($current['public_key'], $record['public_key']) ||
            $current['signing_public_key'] === null ||
            !hash_equals($current['signing_public_key'], $record['signing_public_key'])
        ) {
            json_response(['error' => 'Pass wijzigen mag de public keys niet veranderen.'], 400);
        }
        break;

    case 'replace':
        break;

    default:
        json_response(['error' => 'Onbekende actie.'], 400);
}

save_key_record($pdo, (int) $currentUser['id'], $record);

json_response(['status' => 'saved', 'mode' => $mode]);
