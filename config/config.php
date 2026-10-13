<?php
declare(strict_types=1);

/**
 * Algemene app-configuratie. Alles komt uit .env; zonder geldige waarden
 * start de app niet (zie config_errors() en includes/setup_error.php).
 */

define('APP_NAME', (string) env('APP_NAME', 'Deaddrop'));
define('APP_VERSION', trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'dev');

/**
 * De URL waarmee de browser de app opent. /public is de DocumentRoot,
 * dus APP_URL bevat geen /public (behalve in een submap zoals lokaal onder MAMP).
 */
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));

/**
 * Waar gebruikers de broncode van deze node vinden. De AGPL vraagt dat
 * iedereen die de node gebruikt, bij de broncode kan; pas dit aan als je
 * zelf wijzigingen draait.
 */
define('APP_SOURCE_URL', (string) env('APP_SOURCE_URL', 'https://github.com/Burg-Media/deaddrop'));

define('APP_COOKIE_SECURE', env_bool('APP_COOKIE_SECURE', false));

/**
 * Server-secret voor HMACs (device-secrets, activatiecodes, throttling).
 * Wijzigt deze, dan zijn alle trusted devices ongeldig.
 */
define('APP_KEY', (string) env('APP_KEY', ''));

/**
 * Trusted device gate. Pas op true nadat er een admin-device geactiveerd is.
 */
define('APP_DEVICE_TRUST_REQUIRED', env_bool('APP_DEVICE_TRUST_REQUIRED', false));
define('APP_DEVICE_COOKIE_NAME', (string) env('APP_DEVICE_COOKIE_NAME', 'dd_device'));

/**
 * Extra key-hash voor toekomstige node-admin write actions.
 * De echte key staat nergens opgeslagen, alleen een password_hash.
 */
define('NODE_ADMIN_ACTION_KEY_HASH', (string) env('NODE_ADMIN_ACTION_KEY_HASH', ''));

define('SESSION_IDLE_MINUTES', env_int('SESSION_IDLE_MINUTES', 30));
define('SESSION_MAX_HOURS', env_int('SESSION_MAX_HOURS', 12));

/**
 * Berichten: maximaal 7 dagen, standaard 24 uur.
 */
define('MAX_EXPIRY_MINUTES', env_int('MAX_EXPIRY_MINUTES', 10080));
define('DEFAULT_EXPIRY_MINUTES', env_int('DEFAULT_EXPIRY_MINUTES', 1440));

/**
 * Burn-after-read: na de eerste weergave heeft de ontvanger zoveel tijd en
 * zoveel pogingen om te ontsleutelen. Daarna is de ciphertext weg.
 */
define('READ_WINDOW_MINUTES', 5);
define('MAX_DECRYPT_ATTEMPTS', 5);

/**
 * Sleutelafleiding voor de private pass. Nieuwe en opnieuw ingepakte sleutels
 * gebruiken minimaal dit aantal PBKDF2-SHA256-iteraties (OWASP-richtlijn).
 */
define('KDF_MIN_ITERATIONS', 600000);

/**
 * Bewaartermijn: verlopen, gelezen en vernietigde berichten verdwijnen na
 * zoveel uur helemaal uit de database, inclusief metadata.
 */
define('MESSAGE_RETENTION_HOURS', 24);

/**
 * Maximale lengte van een versleuteld bericht (base64). Ruim genoeg voor
 * 10.000 tekens tekst plus handtekening.
 */
define('MAX_PAYLOAD_LENGTH', 100000);

function config_errors(): array
{
    $errors = [];

    if (APP_URL === '' || !preg_match('#^https?://[^\s]+$#', APP_URL)) {
        $errors[] = 'APP_URL ontbreekt of is geen geldige URL. Zet hem op exact de URL waarmee de browser de app opent.';
    }

    $placeholders = [
        'change-me-to-a-long-random-secret',
        'maak-hier-een-lange-random-secret',
        'lange-stabiele-random-secret',
        'lange-stabiele-random-secret-die-je-niet-wijzigt',
    ];

    if (strlen(APP_KEY) < 32 || in_array(APP_KEY, $placeholders, true)) {
        $errors[] = 'APP_KEY ontbreekt, is korter dan 32 tekens of is nog een voorbeeldwaarde. Maak er een met: php tools/generate_app_key.php';
    }

    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
        if ((string) env($key, '') === '') {
            $errors[] = $key . ' ontbreekt in .env.';
        }
    }

    return $errors;
}
