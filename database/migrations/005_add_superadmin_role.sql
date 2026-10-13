ALTER TABLE users
    MODIFY role ENUM('user', 'member', 'admin', 'superadmin') NOT NULL DEFAULT 'user';
