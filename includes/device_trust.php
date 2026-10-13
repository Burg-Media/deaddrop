<?php
declare(strict_types=1);

function device_trust_tables_exist(PDO $pdo): bool
{
    return db_table_exists($pdo, 'trusted_devices')
        && db_table_exists($pdo, 'device_activation_codes');
}

function device_trust_is_required(PDO $pdo): bool
{
    return APP_DEVICE_TRUST_REQUIRED && device_trust_tables_exist($pdo);
}

function device_secret_hash(string $secret): string
{
    return hash_hmac('sha256', $secret, app_key_material());
}

function activation_code_hash(string $code): string
{
    return hash_hmac('sha256', strtoupper(trim($code)), app_key_material());
}

function app_key_material(): string
{
    return APP_KEY;
}

function trusted_device_cookie_options(): array
{
    return [
        'expires' => time() + (60 * 60 * 24 * 180),
        'path' => '/',
        'domain' => '',
        'secure' => APP_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function issue_trusted_device_cookie(string $publicId, string $secret): void
{
    setcookie(
        APP_DEVICE_COOKIE_NAME,
        $publicId . ':' . $secret,
        trusted_device_cookie_options()
    );
}

function clear_trusted_device_cookie(): void
{
    setcookie(APP_DEVICE_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => APP_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function current_trusted_device(PDO $pdo): ?array
{
    if (!device_trust_tables_exist($pdo)) {
        return null;
    }

    $cookie = (string) ($_COOKIE[APP_DEVICE_COOKIE_NAME] ?? '');

    if (!str_contains($cookie, ':')) {
        return null;
    }

    [$publicId, $secret] = array_pad(explode(':', $cookie, 2), 2, '');

    if (!preg_match('/^[a-f0-9]{64}$/', $publicId) || strlen($secret) < 32) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            public_id,
            user_id,
            label,
            device_secret_hash,
            status
        FROM trusted_devices
        WHERE public_id = ?
          AND status = 'trusted'
        LIMIT 1
    ");
    $stmt->execute([$publicId]);
    $device = $stmt->fetch();

    if (!$device || !hash_equals($device['device_secret_hash'], device_secret_hash($secret))) {
        return null;
    }

    // Alleen de dag bijhouden: genoeg om een vergeten device te herkennen,
    // te grof om iemands gebruikspatroon uit af te lezen.
    $update = $pdo->prepare('UPDATE trusted_devices SET last_seen_at = CURDATE() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < CURDATE()) LIMIT 1');
    $update->execute([(int) $device['id']]);

    return $device;
}

function is_trusted_device(PDO $pdo): bool
{
    if (!device_trust_is_required($pdo)) {
        return true;
    }

    return current_trusted_device($pdo) !== null;
}

function trusted_device_allows_user(PDO $pdo, int $userId): bool
{
    if (!device_trust_is_required($pdo)) {
        return true;
    }

    $device = current_trusted_device($pdo);

    if (!$device) {
        return false;
    }

    return $device['user_id'] === null || (int) $device['user_id'] === $userId;
}
