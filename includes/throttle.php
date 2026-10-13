<?php
declare(strict_types=1);

/**
 * Throttling zonder duurzame IP-log: elke identifier (username, IP, user-id)
 * wordt als HMAC opgeslagen, en de rijen verdwijnen na een uur.
 *
 * Na $freeAttempts pogingen binnen $windowSeconds volgt een lockout die
 * per extra poging verdubbelt, met een plafond van een uur.
 */

function throttle_available(PDO $pdo): bool
{
    return db_table_exists($pdo, 'auth_throttle');
}

function throttle_hash(string $scope, string $identifier): string
{
    return hash_hmac('sha256', $scope . '|' . $identifier, APP_KEY . '|throttle');
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Seconden dat deze identifier nog geblokkeerd is, of 0.
 */
function throttle_locked_for(PDO $pdo, string $scope, string $identifier): int
{
    if (!throttle_available($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare('
        SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS remaining
        FROM auth_throttle
        WHERE scope = ?
          AND identifier_hash = ?
          AND locked_until > NOW()
        LIMIT 1
    ');
    $stmt->execute([$scope, throttle_hash($scope, $identifier)]);
    $row = $stmt->fetch();

    return $row ? max(1, (int) $row['remaining']) : 0;
}

function throttle_hit(PDO $pdo, string $scope, string $identifier, int $freeAttempts, int $windowSeconds): void
{
    if (!throttle_available($pdo)) {
        return;
    }

    $hash = throttle_hash($scope, $identifier);

    $stmt = $pdo->prepare('
        SELECT id, attempts, TIMESTAMPDIFF(SECOND, first_attempt_at, NOW()) AS age
        FROM auth_throttle
        WHERE scope = ?
          AND identifier_hash = ?
        LIMIT 1
    ');
    $stmt->execute([$scope, $hash]);
    $row = $stmt->fetch();

    if (!$row) {
        $insert = $pdo->prepare('
            INSERT INTO auth_throttle (scope, identifier_hash, attempts, first_attempt_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = attempts + 1
        ');
        $insert->execute([$scope, $hash]);
        return;
    }

    if ((int) $row['age'] > $windowSeconds && (int) $row['attempts'] <= $freeAttempts) {
        $reset = $pdo->prepare('UPDATE auth_throttle SET attempts = 1, first_attempt_at = NOW(), locked_until = NULL WHERE id = ?');
        $reset->execute([(int) $row['id']]);
        return;
    }

    $attempts = (int) $row['attempts'] + 1;
    $lockSeconds = 0;

    if ($attempts > $freeAttempts) {
        $lockSeconds = (int) min(3600, 30 * (2 ** min(10, $attempts - $freeAttempts - 1)));
    }

    $update = $pdo->prepare('
        UPDATE auth_throttle
        SET attempts = ?,
            locked_until = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? SECOND), locked_until)
        WHERE id = ?
    ');
    $update->execute([$attempts, $lockSeconds, $lockSeconds, (int) $row['id']]);
}

function throttle_clear(PDO $pdo, string $scope, string $identifier): void
{
    if (!throttle_available($pdo)) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM auth_throttle WHERE scope = ? AND identifier_hash = ?');
    $stmt->execute([$scope, throttle_hash($scope, $identifier)]);
}

function throttle_wait_text(int $seconds): string
{
    $minutes = (int) ceil($seconds / 60);

    return $minutes <= 1 ? 'een minuut' : $minutes . ' minuten';
}
