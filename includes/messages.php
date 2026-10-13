<?php
declare(strict_types=1);

/**
 * Burn-after-read, serverside afgedwongen:
 *
 *   unopened --eerste weergave--------------> reading (leesvenster van READ_WINDOW_MINUTES)
 *   reading  --browser bevestigt ontsleuteling--> opened    + ciphertext gewist
 *   reading  --leesvenster verlopen-----------> destroyed + ciphertext gewist
 *   reading  --MAX_DECRYPT_ATTEMPTS fout------> locked    + ciphertext gewist
 *   *        --expires_at bereikt-------------> expired   + ciphertext gewist
 *
 * Een tweede weergave van hetzelfde bericht toont nooit opnieuw de ciphertext.
 */

function wipe_message(PDO $pdo, int $messageId, string $status): void
{
    $stmt = $pdo->prepare("
        UPDATE messages
        SET encrypted_payload = NULL,
            iv = NULL,
            salt = NULL,
            status = ?,
            destroyed_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$status, $messageId]);
}

/**
 * Zet een ongeopend bericht atomair op 'reading'. Geeft true als déze request
 * het bericht opende; bij een tweede request (of een race) false.
 */
function claim_message_for_reading(PDO $pdo, int $messageId): bool
{
    $stmt = $pdo->prepare("
        UPDATE messages
        SET status = 'reading',
            opened_at = NOW()
        WHERE id = ?
          AND status = 'unopened'
          AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$messageId]);

    return $stmt->rowCount() === 1;
}

/**
 * Haalt een bericht van de ingelogde ontvanger op en ruimt het direct op als
 * het verlopen is of het leesvenster voorbij is.
 */
function find_recipient_message(PDO $pdo, string $ref, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.public_id,
            m.deaddrop_id,
            m.recipient_user_id,
            m.encrypted_payload,
            m.iv,
            m.salt,
            m.status,
            m.expires_at,
            m.opened_at,
            m.failed_attempts,
            m.expires_at <= NOW() AS is_expired,
            (m.status = 'reading' AND m.opened_at <= DATE_SUB(NOW(), INTERVAL " . READ_WINDOW_MINUTES . " MINUTE)) AS window_closed,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(m.opened_at, INTERVAL " . READ_WINDOW_MINUTES . " MINUTE))) AS window_remaining,
            d.public_id AS deaddrop_public_id,
            d.name AS deaddrop_name
        FROM messages m
        INNER JOIN deaddrops d ON d.id = m.deaddrop_id
        WHERE m.public_id = ?
          AND m.recipient_user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$ref, $userId]);
    $message = $stmt->fetch();

    if (!$message) {
        return null;
    }

    if (in_array($message['status'], ['unopened', 'reading'], true) && (int) $message['is_expired'] === 1) {
        wipe_message($pdo, (int) $message['id'], 'expired');
        $message['status'] = 'expired';
    } elseif ((int) $message['window_closed'] === 1) {
        wipe_message($pdo, (int) $message['id'], 'destroyed');
        $message['status'] = 'destroyed';
    }

    return $message;
}
