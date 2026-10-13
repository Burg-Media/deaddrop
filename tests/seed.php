<?php
declare(strict_types=1);

/**
 * Testdata voor tests/e2e.mjs: gebruikers en een channel, nog zonder sleutels
 * (die maakt de browser). Alleen voor een testdatabase.
 *
 *   DB_DATABASE=deaddrop_test php tests/seed.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/app.php';

if (!str_contains((string) env('DB_DATABASE', ''), 'test') && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Weigert: DB_DATABASE bevat geen 'test'. Gebruik --force als je het zeker weet.\n");
    exit(1);
}

$password = (string) (getenv('SEED_PASSWORD') ?: 'test-wachtwoord-123');
$users = $argv[1] ?? 'ops:superadmin,alice:user,bob:user,carol:user';
$channel = (string) (getenv('SEED_CHANNEL') ?: 'Redactie');
$ids = [];

foreach (explode(',', $users) as $spec) {
    [$name, $role] = explode(':', $spec);
    $stmt = $pdo->prepare('INSERT INTO users (public_id, username, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([generate_public_id(), $name, password_hash($password, PASSWORD_DEFAULT), $role]);
    $ids[$name] = (int) $pdo->lastInsertId();
}

$owner = array_key_first($ids);
$stmt = $pdo->prepare('INSERT INTO deaddrops (public_id, name, owner_user_id, default_expiry_minutes, max_expiry_minutes, created_at) VALUES (?, ?, ?, 1440, 10080, NOW())');
$stmt->execute([generate_public_id(), $channel, $ids[$owner]]);
$dropId = (int) $pdo->lastInsertId();

foreach ($ids as $name => $id) {
    $member = $pdo->prepare('INSERT INTO deaddrop_members (deaddrop_id, user_id, role, created_at) VALUES (?, ?, ?, NOW())');
    $member->execute([$dropId, $id, $name === $owner ? 'owner' : 'member']);
}

echo 'seeded ' . count($ids) . " users in channel {$channel}\n";
