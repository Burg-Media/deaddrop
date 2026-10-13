-- Tweede sleutelpaar (ECDSA P-256) om berichten te ondertekenen, en de
-- sleutelafleiding als versieveld zodat iteraties later omhoog kunnen
-- zonder iedereen buiten te sluiten. Bestaande sleutels staan op 250.000
-- en worden bij de eerstvolgende ontsleuteling naar 600.000 gebracht.
ALTER TABLE user_keys
    ADD COLUMN signing_public_key LONGTEXT NULL AFTER private_key_salt,
    ADD COLUMN encrypted_signing_private_key LONGTEXT NULL AFTER signing_public_key,
    ADD COLUMN signing_private_key_iv VARCHAR(255) NULL AFTER encrypted_signing_private_key,
    ADD COLUMN kdf_algo VARCHAR(32) NOT NULL DEFAULT 'PBKDF2-SHA256' AFTER signing_private_key_iv,
    ADD COLUMN kdf_iterations INT NOT NULL DEFAULT 250000 AFTER kdf_algo;
