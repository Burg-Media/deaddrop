<?php
declare(strict_types=1);

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_guest(): void
{
    if (is_logged_in()) {
        redirect('dashboard.php');
    }
}

function is_admin(?array $user): bool
{
    return in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'superadmin'], true);
}

function require_admin(?array $user): void
{
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Geen toegang.');
    }
}

function is_superadmin(?array $user): bool
{
    return strtolower((string) ($user['role'] ?? '')) === 'superadmin';
}

function require_superadmin(?array $user): void
{
    if (!is_superadmin($user)) {
        http_response_code(403);
        exit('Geen toegang.');
    }
}

function user_has_keys(PDO $pdo, int $userId): bool
{
    if (!db_table_exists($pdo, 'user_keys')) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT id FROM user_keys WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);

    return (bool) $stmt->fetch();
}

function get_logged_in_user(PDO $pdo): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    $deletedSelect = db_column_exists($pdo, 'users', 'deleted_at') ? ', deleted_at' : ', NULL AS deleted_at';

    $stmt = $pdo->prepare('SELECT id, public_id, username, role, created_at, last_login_at' . $deletedSelect . ' FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([current_user_id()]);

    $user = $stmt->fetch();

    if (!$user || $user['deleted_at'] !== null) {
        return null;
    }

    return $user;
}
