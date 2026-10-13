<?php
declare(strict_types=1);

/**
 * Maakt een gebruiker aan vanaf de command line. Nodig voor de eerste
 * beheerder; daarna kan dat via de webinterface of de terminal.
 *
 *   php tools/create_user.php <username> [--role=superadmin|admin|user]
 *
 * Het tijdelijke wachtwoord wordt gegenereerd en één keer getoond.
 * De private pass kiest de gebruiker zelf bij de eerste login, in de browser.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/app.php';

// Zelf parsen: getopt() stopt bij het eerste argument zonder streepjes,
// waardoor `create_user.php naam --role=superadmin` de rol zou negeren.
$username = '';
$role = 'user';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--role=')) {
        $role = substr($arg, 7);
    } elseif ($username === '') {
        $username = $arg;
    }
}

if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Gebruik: php tools/create_user.php <username> [--role=superadmin|admin|user]\n");
    fwrite(STDERR, "Username: 3 tot 50 tekens, letters, cijfers, punt, underscore of streepje.\n");
    exit(1);
}

if (!in_array($role, ['superadmin', 'admin', 'user'], true)) {
    fwrite(STDERR, "Rol moet superadmin, admin of user zijn.\n");
    exit(1);
}

$exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$exists->execute([$username]);

if ($exists->fetch()) {
    fwrite(STDERR, "Deze username bestaat al.\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'kx'), '=');

$insert = $pdo->prepare('INSERT INTO users (public_id, username, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())');
$insert->execute([generate_public_id(), $username, password_hash($password, PASSWORD_DEFAULT), $role]);

echo "Gebruiker {$username} ({$role}) aangemaakt.\n";
echo "Tijdelijk wachtwoord: {$password}\n";
echo "Log in op " . APP_URL . "/login.php en kies daarna je private pass.\n";
