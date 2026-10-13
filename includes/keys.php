<?php
declare(strict_types=1);

/**
 * Sleutelrecords. De server ziet alleen public keys en private keys die in de
 * browser met de private pass zijn ingepakt (PBKDF2 + AES-GCM). Hij kan ze
 * opslaan en uitdelen, niet openen.
 */

const KEY_RECORD_COLUMNS = [
    'public_key',
    'encrypted_private_key',
    'private_key_iv',
    'private_key_salt',
    'signing_public_key',
    'encrypted_signing_private_key',
    'signing_private_key_iv',
    'kdf_algo',
    'kdf_iterations',
];

/**
 * Controleert een sleutelrecord uit de browser. Geeft het genormaliseerde
 * record terug, of null als iets ontbreekt, geen base64 is of te groot is.
 */
function validate_key_record(array $data): ?array
{
    $limits = [
        'public_key' => 4000,
        'encrypted_private_key' => 12000,
        'private_key_iv' => 24,
        'private_key_salt' => 64,
        'signing_public_key' => 1000,
        'encrypted_signing_private_key' => 2000,
        'signing_private_key_iv' => 24,
    ];

    $record = [];

    foreach ($limits as $field => $max) {
        $value = trim((string) ($data[$field] ?? ''));

        if (!is_strict_base64($value, $max)) {
            return null;
        }

        $record[$field] = $value;
    }

    $iterations = (int) ($data['kdf_iterations'] ?? 0);

    if ($iterations < KDF_MIN_ITERATIONS || $iterations > 10000000) {
        return null;
    }

    if ((string) ($data['kdf_algo'] ?? '') !== 'PBKDF2-SHA256') {
        return null;
    }

    $record['kdf_algo'] = 'PBKDF2-SHA256';
    $record['kdf_iterations'] = $iterations;

    return $record;
}

function load_key_record(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT ' . implode(', ', KEY_RECORD_COLUMNS) . ' FROM user_keys WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $record = $stmt->fetch();

    if (!$record) {
        return null;
    }

    $record['kdf_iterations'] = (int) $record['kdf_iterations'];

    return $record;
}

function save_key_record(PDO $pdo, int $userId, array $record): void
{
    $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', KEY_RECORD_COLUMNS));
    $values = array_map(static fn (string $column) => $record[$column], KEY_RECORD_COLUMNS);
    $values[] = $userId;

    $stmt = $pdo->prepare('UPDATE user_keys SET ' . $assignments . ', updated_at = NOW() WHERE user_id = ? LIMIT 1');
    $stmt->execute($values);
}
