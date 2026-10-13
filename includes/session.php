<?php
declare(strict_types=1);

/**
 * Sessies: strikte modus, eigen opslagmap buiten de docroot (op shared hosting
 * delen andere sites anders de standaardmap), en een idle- en maximale levensduur.
 */
function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (SESSION_IDLE_MINUTES * 60));

    $sessionDir = APP_ROOT . '/storage/sessions';

    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }

    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_name('dd_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => APP_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();

    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $now = time();
    $idleLimit = SESSION_IDLE_MINUTES * 60;
    $absoluteLimit = SESSION_MAX_HOURS * 3600;
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $startedAt = (int) ($_SESSION['started_at'] ?? 0);

    if (($lastActivity && $now - $lastActivity > $idleLimit) || ($startedAt && $now - $startedAt > $absoluteLimit)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['login_error'] = 'Je sessie is verlopen. Log opnieuw in.';
        return;
    }

    $_SESSION['last_activity'] = $now;
}
