<?php
declare(strict_types=1);

/**
 * Centrale bootstrap voor webpagina's en actions.
 * Dit bestand include je bovenaan elke PHP-pagina/action.
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/session.php';

start_secure_session();

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/device_trust.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/node_admin.php';
require_once __DIR__ . '/assets.php';

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$deviceTrustAllowedScripts = [
    'index.php',
    'login.php',
    'login_action.php',
    'activate.php',
    'activate_device.php',
    'logout.php',
    'verify.php',
];

if (
    device_trust_is_required($pdo) &&
    !in_array($currentScript, $deviceTrustAllowedScripts, true) &&
    !is_trusted_device($pdo)
) {
    redirect('activate.php');
}

$keySetupAllowedScripts = [
    'key_setup.php',
    'store_user_keys.php',
    'logout.php',
    'login.php',
    'verify.php',
];

if (
    is_logged_in() &&
    !in_array($currentScript, $keySetupAllowedScripts, true) &&
    !user_has_keys($pdo, (int) current_user_id())
) {
    redirect('key_setup.php');
}
