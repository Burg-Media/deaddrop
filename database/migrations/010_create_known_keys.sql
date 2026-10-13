-- Welke sleutel (fingerprint) een gebruiker eerder van een contact zag.
-- De browser pint dezelfde fingerprint ook lokaal, zodat een operator die
-- deze tabel leegmaakt de waarschuwing bij een gewijzigde sleutel niet wegkrijgt.
CREATE TABLE known_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    observer_user_id INT NOT NULL,
    subject_user_id INT NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    first_seen_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    UNIQUE KEY uq_known_keys (observer_user_id, subject_user_id),
    CONSTRAINT fk_known_keys_observer
        FOREIGN KEY (observer_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_known_keys_subject
        FOREIGN KEY (subject_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
