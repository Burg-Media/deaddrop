<?php
declare(strict_types=1);

/**
 * Kern die zowel de webpagina's als de CLI-tools laden:
 * .env, configuratie (fail-fast), database en helpers.
 */

define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/env.php';

load_env(APP_ROOT . '/.env');

require_once APP_ROOT . '/config/config.php';
require_once __DIR__ . '/setup_error.php';

$configErrors = config_errors();

if ($configErrors) {
    show_setup_error($configErrors);
}

require_once APP_ROOT . '/config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/throttle.php';
