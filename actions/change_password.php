<?php
declare(strict_types=1);

/**
 * Accountwachtwoord wijzigen (los van de private pass: dit wachtwoord is
 * alleen om in te loggen en kent de server wel, als hash).
 */

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('profile.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$repeat = (string) ($_POST['new_password_repeat'] ?? '');

$fail = static function (string $message): never {
    $_SESSION['password_error'] = $message;
    redirect('profile.php#wachtwoord');
};

$lockedFor = throttle_locked_for($pdo, 'password', (string) $currentUser['id']);

if ($lockedFor > 0) {
    $fail('Te veel pogingen. Probeer het over ' . throttle_wait_text($lockedFor) . ' opnieuw.');
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int) $currentUser['id']]);
$hash = (string) ($stmt->fetch()['password_hash'] ?? '');

if (!password_verify($current, $hash)) {
    throttle_hit($pdo, 'password', (string) $currentUser['id'], 5, 900);
    $fail('Je huidige wachtwoord klopt niet.');
}

if (strlen($new) < 12) {
    $fail('Gebruik minimaal 12 tekens.');
}

if ($new !== $repeat) {
    $fail('De twee nieuwe wachtwoorden zijn niet gelijk.');
}

$update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1');
$update->execute([password_hash($new, PASSWORD_DEFAULT), (int) $currentUser['id']]);

session_regenerate_id(true);
$_SESSION['password_notice'] = 'Je accountwachtwoord is gewijzigd.';

redirect('profile.php#wachtwoord');
