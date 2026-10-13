<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/keys.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('key_setup.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

if (!db_table_exists($pdo, 'user_keys') || !db_column_exists($pdo, 'user_keys', 'signing_public_key')) {
    $_SESSION['key_setup_error'] = 'De database is niet bijgewerkt. Draai eerst php tools/migrate.php.';
    redirect('key_setup.php');
}

if (user_has_keys($pdo, (int) $currentUser['id'])) {
    $_SESSION['key_setup_error'] = 'Er bestaat al een keypair voor deze user.';
    redirect('dashboard.php');
}

$record = validate_key_record($_POST);

if ($record === null) {
    $_SESSION['key_setup_error'] = 'Key setup mislukt: keydata ontbreekt of is ongeldig.';
    redirect('key_setup.php');
}

try {
    $columns = KEY_RECORD_COLUMNS;
    $insert = $pdo->prepare(
        'INSERT INTO user_keys (user_id, ' . implode(', ', $columns) . ', created_at) VALUES (?, '
        . implode(', ', array_fill(0, count($columns), '?')) . ', NOW())'
    );
    $insert->execute(array_merge(
        [(int) $currentUser['id']],
        array_map(static fn (string $column) => $record[$column], $columns)
    ));
} catch (Throwable $e) {
    error_log('Key setup failed: ' . $e->getMessage());
    $_SESSION['key_setup_error'] = 'Key setup opslaan is mislukt.';
    redirect('key_setup.php');
}

redirect('profile.php?nieuw=1');
