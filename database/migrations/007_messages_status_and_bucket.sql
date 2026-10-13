-- Volledige toestandsmachine voor burn-after-read, en created_at gelijk aan de
-- 5-minutenbucket zodat het exacte verzendmoment nergens bewaard blijft.
ALTER TABLE messages
    MODIFY status ENUM('unopened', 'reading', 'opened', 'destroyed', 'expired', 'locked') NOT NULL DEFAULT 'unopened',
    MODIFY failed_attempts INT NOT NULL DEFAULT 0;

UPDATE messages SET created_at = created_bucket WHERE created_bucket IS NOT NULL;
