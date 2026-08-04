-- ============================================================
-- Email + password-recovery migration. Additive and re-runnable —
-- run once against an existing database, no data is dropped:
--
--   mysql -u root fp3 < config/migration_email_reset.sql
--
-- A fresh install via schema.sql already includes these changes.
-- ============================================================

USE fp3;

-- Recovery email on each account. Nullable so pre-existing accounts survive the
-- migration; new registrations require it (enforced in Auth::register).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER username;

-- Unique email (multiple NULLs allowed by MySQL/MariaDB). Guarded so re-runs pass.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE users ADD UNIQUE INDEX uq_users_email (email)',
    'SELECT "uq_users_email already present" AS note');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- One-time password-reset tokens. We store only the SHA-256 of the token, so a DB
-- leak can't be used to reset anyone's password. Rows expire and are single-use.
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,          -- DATETIME: no implicit ON UPDATE bump
    used_at    DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_token (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
);
