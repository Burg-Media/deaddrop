CREATE TABLE trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(64) NOT NULL UNIQUE,
    user_id INT NULL,
    label VARCHAR(120) NOT NULL,
    device_secret_hash CHAR(64) NOT NULL UNIQUE,
    status ENUM('trusted','revoked') NOT NULL DEFAULT 'trusted',
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trusted_devices_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE device_activation_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(64) NOT NULL UNIQUE,
    user_id INT NULL,
    label VARCHAR(120) NOT NULL,
    code_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_device_activation_codes_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_device_activation_codes_created_by
        FOREIGN KEY (created_by_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);
