-- Basisschema: de vier tabellen waar de rest van de migraties op voortbouwt
-- (gelijk aan een mysqldump --no-data van een werkende node uit mei 2026).
-- Een bestaande node heeft deze al; draai daar `php tools/migrate.php --baseline=005`.

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(64) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'member', 'admin') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deaddrops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(64) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    owner_user_id INT NOT NULL,
    default_expiry_minutes INT NOT NULL DEFAULT 1440,
    max_expiry_minutes INT NOT NULL DEFAULT 10080,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deaddrops_owner
        FOREIGN KEY (owner_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deaddrop_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deaddrop_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at DATETIME NULL,
    UNIQUE KEY unique_deaddrop_member (deaddrop_id, user_id),
    CONSTRAINT fk_members_deaddrop
        FOREIGN KEY (deaddrop_id)
        REFERENCES deaddrops(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_members_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bewust geen afzenderkolom: wie een bericht stuurde staat alleen ondertekend
-- ín de versleutelde payload, nooit leesbaar in de database.
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(64) NOT NULL UNIQUE,
    deaddrop_id INT NOT NULL,
    recipient_user_id INT NOT NULL,
    encrypted_payload LONGTEXT NULL,
    iv VARCHAR(255) NULL,
    salt VARCHAR(255) NULL,
    status ENUM('unopened', 'reading', 'opened', 'destroyed', 'expired', 'locked') NOT NULL DEFAULT 'unopened',
    created_bucket DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    opened_at DATETIME NULL,
    destroyed_at DATETIME NULL,
    failed_attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_recipient_status (recipient_user_id, status),
    INDEX idx_messages_expires_at (expires_at),
    CONSTRAINT fk_messages_deaddrop
        FOREIGN KEY (deaddrop_id)
        REFERENCES deaddrops(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_messages_recipient
        FOREIGN KEY (recipient_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
