ALTER TABLE users
    ADD COLUMN deleted_at DATETIME NULL AFTER last_login_at,
    ADD COLUMN deleted_by_user_id INT NULL AFTER deleted_at,
    ADD INDEX idx_users_deleted_at (deleted_at),
    ADD CONSTRAINT fk_users_deleted_by
        FOREIGN KEY (deleted_by_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL;
