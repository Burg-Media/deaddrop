-- Throttling voor login, device-activatie en berichtaanmaak.
-- identifier_hash is een HMAC met APP_KEY: er staat geen leesbaar IP of username in,
-- en de rijen verdwijnen na een uur via cron/cleanup_expired.php.
CREATE TABLE auth_throttle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(20) NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    UNIQUE KEY uq_auth_throttle (scope, identifier_hash),
    INDEX idx_auth_throttle_first (first_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
