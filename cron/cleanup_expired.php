<?php
declare(strict_types=1);

/**
 * Opruimen, elke 5 minuten via cron:
 *
 *   * /5 * * * *  /pad/naar/php /pad/naar/deaddrop/cron/cleanup_expired.php
 *
 * Op Plesk de Plesk-binary gebruiken (/opt/plesk/php/<versie>/bin/php), niet de kale `php`.
 *
 * Verlopen berichten en verlopen leesvensters verliezen direct hun ciphertext.
 * Na MESSAGE_RETENTION_HOURS verdwijnt de hele rij, zodat ook de metadata
 * (wie ontving wanneer iets in welk channel) weg is.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/app.php';

$report = [];

$report['expired'] = $pdo->exec("
    UPDATE messages
    SET encrypted_payload = NULL,
        iv = NULL,
        salt = NULL,
        status = 'expired',
        destroyed_at = NOW()
    WHERE status IN ('unopened', 'reading')
      AND expires_at <= NOW()
");

$report['read_window_closed'] = $pdo->exec("
    UPDATE messages
    SET encrypted_payload = NULL,
        iv = NULL,
        salt = NULL,
        status = 'destroyed',
        destroyed_at = NOW()
    WHERE status = 'reading'
      AND opened_at <= DATE_SUB(NOW(), INTERVAL " . READ_WINDOW_MINUTES . " MINUTE)
");

// Vangnet: een eindstatus heeft nooit nog ciphertext.
$pdo->exec("
    UPDATE messages
    SET encrypted_payload = NULL, iv = NULL, salt = NULL
    WHERE status IN ('opened', 'destroyed', 'expired', 'locked')
      AND (encrypted_payload IS NOT NULL OR iv IS NOT NULL OR salt IS NOT NULL)
");

$report['messages_deleted'] = $pdo->exec("
    DELETE FROM messages
    WHERE (status IN ('opened', 'destroyed', 'expired', 'locked')
           AND COALESCE(destroyed_at, expires_at) <= DATE_SUB(NOW(), INTERVAL " . MESSAGE_RETENTION_HOURS . " HOUR))
       OR expires_at <= DATE_SUB(NOW(), INTERVAL " . MESSAGE_RETENTION_HOURS . " HOUR)
");

if (db_table_exists($pdo, 'device_activation_codes')) {
    $report['activation_codes_deleted'] = $pdo->exec("
        DELETE FROM device_activation_codes
        WHERE used_at IS NOT NULL
           OR expires_at <= NOW()
    ");
}

if (db_table_exists($pdo, 'trusted_devices')) {
    $report['revoked_devices_deleted'] = $pdo->exec("
        DELETE FROM trusted_devices
        WHERE status = 'revoked'
          AND revoked_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
}

if (db_table_exists($pdo, 'auth_throttle')) {
    $report['throttle_rows_deleted'] = $pdo->exec("
        DELETE FROM auth_throttle
        WHERE first_attempt_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
          AND (locked_until IS NULL OR locked_until <= NOW())
    ");
}

if (in_array('--verbose', $argv, true)) {
    foreach ($report as $key => $count) {
        echo str_pad($key, 28) . (int) $count . "\n";
    }
}
