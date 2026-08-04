-- ============================================================
-- Accounts feature migration — run this ONCE against an existing
-- database (it does NOT drop/recreate anything, so your data is safe).
--
--   mysql -u root -p fp3 < config/migration_users.sql
--
-- A fresh install via schema.sql already includes these changes.
-- ============================================================

USE fp3;

-- User accounts: username + password_hash, plus an optional encrypted API key.
CREATE TABLE IF NOT EXISTS users (
    user_id       BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,           -- password_hash(), never the raw password
    api_key_enc   VARBINARY(1024) DEFAULT NULL,    -- AES-256-GCM blob: nonce|tag|ciphertext
    api_provider  VARCHAR(20) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_username_nonempty CHECK (CHAR_LENGTH(username) > 0)
);

-- Tie each interview session to the account that created it. NULL = orphaned
-- (created before accounts existed) — hidden from every user's list.
ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER session_id;

-- Add the FK only if it isn't there yet (re-runnable). MySQL has no
-- "ADD CONSTRAINT IF NOT EXISTS", so guard it.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sessions'
      AND CONSTRAINT_NAME = 'fk_session_user'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE sessions ADD CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE',
    'SELECT "fk_session_user already present" AS note'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
