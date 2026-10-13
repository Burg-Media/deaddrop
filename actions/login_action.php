<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Vul je gebruikersnaam en wachtwoord in.';
    redirect('login.php');
}

/**
 * Throttling op username én IP (beide als HMAC opgeslagen). Werkt ook als de
 * username niet bestaat, zodat de lockout niets verraadt over welke accounts er zijn.
 */
$usernameKey = strtolower($username);
$lockedFor = max(
    throttle_locked_for($pdo, 'login_user', $usernameKey),
    throttle_locked_for($pdo, 'login_ip', client_ip())
);

if ($lockedFor > 0) {
    $_SESSION['login_error'] = 'Te veel mislukte pogingen. Probeer het over ' . throttle_wait_text($lockedFor) . ' opnieuw.';
    redirect('login.php');
}

$hasDeletedAt = db_column_exists($pdo, 'users', 'deleted_at');
$deletedFilter = $hasDeletedAt ? ' AND deleted_at IS NULL' : '';

$stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?' . $deletedFilter . ' LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

/**
 * Altijd een password_verify, ook als de user niet bestaat: anders verraadt de
 * responstijd welke usernames er zijn.
 */
$hash = $user ? (string) $user['password_hash'] : password_hash('deaddrop-timing-dummy', PASSWORD_DEFAULT);
$passwordOk = password_verify($password, $hash);

if (!$user || !$passwordOk) {
    throttle_hit($pdo, 'login_user', $usernameKey, 5, 900);
    throttle_hit($pdo, 'login_ip', client_ip(), 20, 900);
    $_SESSION['login_error'] = 'Ongeldige gebruikersnaam of wachtwoord.';
    redirect('login.php');
}

if (device_trust_is_required($pdo) && current_trusted_device($pdo) === null) {
    $_SESSION['device_activate_error'] = 'Login klopt, maar dit device is nog niet vertrouwd. Activeer dit device eerst met een activatiecode.';
    redirect('activate.php');
}

if (!trusted_device_allows_user($pdo, (int) $user['id'])) {
    $_SESSION['login_error'] = 'Dit device is niet gekoppeld aan deze user.';
    redirect('login.php');
}

throttle_clear($pdo, 'login_user', $usernameKey);

if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
}

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['started_at'] = time();
$_SESSION['last_activity'] = time();

// Alleen de dag: de exacte inlogtijd hoort nergens bewaard te worden.
$update = $pdo->prepare('UPDATE users SET last_login_at = CURDATE() WHERE id = ?');
$update->execute([(int) $user['id']]);

redirect('dashboard.php');
