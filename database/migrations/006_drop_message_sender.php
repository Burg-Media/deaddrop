<?php
declare(strict_types=1);

/**
 * messages.sender_user_id weg. Hij werd nergens gevuld, maar de eerste die hem
 * "even invult" maakt van elk bericht een toeschrijfbaar bericht in de database.
 * De afzender staat voortaan alleen ondertekend in de versleutelde payload.
 */
return static function (PDO $pdo): void {
    if (!db_column_exists($pdo, 'messages', 'sender_user_id')) {
        return;
    }

    $fk = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'messages'
          AND COLUMN_NAME = 'sender_user_id'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($fk as $name) {
        $pdo->exec('ALTER TABLE messages DROP FOREIGN KEY `' . str_replace('`', '', $name) . '`');
    }

    $pdo->exec('ALTER TABLE messages DROP COLUMN sender_user_id');
};
