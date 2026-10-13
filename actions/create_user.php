<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$currentUser = get_logged_in_user($pdo);

if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

require_admin($currentUser);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin_users.php');
}

verify_csrf_token($_POST['csrf_token'] ?? null);

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$source = (string) ($_POST['source'] ?? '');
$returnPath = $source === 'terminal' ? 'terminal.php' : 'admin_users.php';

$setCreateError = static function (string $message) use ($source, $returnPath): never {
    if ($source === 'terminal') {
        $_SESSION['terminal_error'] = $message;
        redirect($returnPath);
    }

    $_SESSION['user_create_error'] = $message;
    redirect($returnPath);
};

if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
    $setCreateError('Gebruik 3 tot 50 tekens: letters, cijfers, punt, underscore of streepje.');
}

if (strlen($password) < 12) {
    $setCreateError('Gebruik een tijdelijk wachtwoord van minimaal 12 tekens.');
}

$existingStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$existingStmt->execute([$username]);

if ($existingStmt->fetch()) {
    $setCreateError('Deze username bestaat al.');
}

$roleStmt = $pdo->prepare("
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
");
$roleStmt->execute();
$roleColumnType = (string) ($roleStmt->fetch()['COLUMN_TYPE'] ?? '');
$normalRole = 'member';

if (preg_match("/^enum\\((.*)\\)$/", $roleColumnType, $matches)) {
    preg_match_all("/'([^']+)'/", $matches[1], $roleMatches);
    $allowedRoles = $roleMatches[1] ?? [];

    if (!in_array($normalRole, $allowedRoles, true)) {
        $normalRole = in_array('user', $allowedRoles, true)
            ? 'user'
            : (array_values(array_filter($allowedRoles, static fn (string $role): bool => $role !== 'admin'))[0] ?? 'member');
    }
}

try {
    $insert = $pdo->prepare("
        INSERT INTO users (
            public_id,
            username,
            password_hash,
            role,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $insert->execute([
        generate_public_id(),
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $normalRole,
    ]);
} catch (Throwable $e) {
    error_log('User create failed: ' . $e->getMessage());
    $setCreateError('User aanmaken is mislukt.');
}

if ($source === 'terminal') {
    $_SESSION['terminal_notice'] = 'User aangemaakt: ' . $username . '. Laat deze user daarna zelf key setup afronden.';
    redirect($returnPath);
}

$_SESSION['user_create_notice'] = 'User aangemaakt: ' . $username . '. Laat deze user daarna zelf key setup afronden.';

redirect($returnPath);
