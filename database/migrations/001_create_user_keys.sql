CREATE TABLE user_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    public_key LONGTEXT NOT NULL,
    encrypted_private_key LONGTEXT NOT NULL,
    private_key_iv VARCHAR(255) NOT NULL,
    private_key_salt VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_user_keys_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);
