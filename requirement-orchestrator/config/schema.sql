-- ============================================================
-- FP3 Database Setup
-- ============================================================

DROP DATABASE IF EXISTS fp3;
CREATE DATABASE IF NOT EXISTS fp3
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fp3;

-- ============================================================
-- Drop tables in child-first order (FK safety)
-- ============================================================

DROP TABLE IF EXISTS llm_requests;
DROP TABLE IF EXISTS generated_plans;
DROP TABLE IF EXISTS domain_state;
DROP TABLE IF EXISTS conversation_log;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS users;   -- parent of sessions.user_id + password_resets — dropped last

-- ============================================================
-- Tables
-- ============================================================

-- User accounts (accounts feature): username + password_hash, plus an optional
-- API key encrypted at rest (AES-256-GCM blob: nonce|tag|ciphertext).
CREATE TABLE users (
    user_id       BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    email         VARCHAR(255) NULL UNIQUE,          -- recovery email (unique; NULLs allowed)
    password_hash VARCHAR(255) NOT NULL,
    api_key_enc   VARBINARY(1024) DEFAULT NULL,
    api_provider  VARCHAR(20) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_username_nonempty CHECK (CHAR_LENGTH(username) > 0)
);

-- One-time, single-use password-reset tokens (only the SHA-256 hash is stored).
CREATE TABLE password_resets (
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

CREATE TABLE sessions (
    session_id      BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,          -- owning account; NULL = orphaned
    session_token   CHAR(64) NOT NULL UNIQUE,
    technical_level ENUM(
        'NON_TECHNICAL',
        'TECHNICAL',
        'MIXED'
    ) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_token_length CHECK (CHAR_LENGTH(session_token) = 64),
    CONSTRAINT fk_session_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE
);

CREATE TABLE conversation_log (
    log_id     BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    role ENUM(
        'USER',
        'AGENT',
        'SYSTEM'
    ) NOT NULL,
    message    TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_message_nonempty CHECK (CHAR_LENGTH(message) > 0),
    CONSTRAINT fk_conversation_session
        FOREIGN KEY (session_id)
        REFERENCES sessions(session_id)
        ON DELETE CASCADE,
    INDEX idx_conversation_session (session_id)
);

CREATE TABLE domain_state (
    state_id   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL UNIQUE,
    pain_points       ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    data_sources      ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    data_access       ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    end_result        ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    stakeholders      ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    audience_type     ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    current_process   ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    interaction_model ENUM('OPEN','COVERED','PENDING') NOT NULL DEFAULT 'OPEN',
    domain_json JSON NOT NULL DEFAULT (JSON_OBJECT()),
    CONSTRAINT chk_domain_json_valid CHECK (JSON_VALID(domain_json)),
    CONSTRAINT fk_domain_session
        FOREIGN KEY (session_id)
        REFERENCES sessions(session_id)
        ON DELETE CASCADE
);

CREATE TABLE generated_plans (
    plan_id    BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    prompt_1   LONGTEXT NOT NULL,
    prompt_2   LONGTEXT NOT NULL,
    prompt_3   LONGTEXT NOT NULL,
    prompt_4   LONGTEXT NOT NULL,
    prompt_5   LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_prompt1_nonempty CHECK (CHAR_LENGTH(prompt_1) > 0),
    CONSTRAINT chk_prompt2_nonempty CHECK (CHAR_LENGTH(prompt_2) > 0),
    CONSTRAINT chk_prompt3_nonempty CHECK (CHAR_LENGTH(prompt_3) > 0),
    CONSTRAINT chk_prompt4_nonempty CHECK (CHAR_LENGTH(prompt_4) > 0),
    CONSTRAINT chk_prompt5_nonempty CHECK (CHAR_LENGTH(prompt_5) > 0),
    CONSTRAINT fk_plan_session
        FOREIGN KEY (session_id)
        REFERENCES sessions(session_id)
        ON DELETE CASCADE,
    INDEX idx_plan_session (session_id)
);

CREATE TABLE llm_requests (
    request_id    BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id    BIGINT UNSIGNED NOT NULL,
    provider      VARCHAR(50)  NOT NULL,
    model_name    VARCHAR(100) NOT NULL,
    chain_step    TINYINT UNSIGNED NOT NULL,
    route_type    ENUM(
        'DEPENDENCY_RESOLUTION',
        'IN_SCOPE',
        'OUT_OF_SCOPE'
    ) NOT NULL,
    prompt_text   LONGTEXT NOT NULL,
    response_text LONGTEXT,
    input_tokens  INT UNSIGNED DEFAULT NULL,
    output_tokens INT UNSIGNED DEFAULT NULL,
    latency_ms    INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_provider_nonempty CHECK (CHAR_LENGTH(provider) > 0),
    CONSTRAINT chk_model_nonempty    CHECK (CHAR_LENGTH(model_name) > 0),
    CONSTRAINT chk_prompt_nonempty   CHECK (CHAR_LENGTH(prompt_text) > 0),
    CONSTRAINT chk_chain_step_valid  CHECK (chain_step BETWEEN 1 AND 5),
    CONSTRAINT fk_llm_session
        FOREIGN KEY (session_id)
        REFERENCES sessions(session_id)
        ON DELETE CASCADE,
    INDEX idx_llm_session (session_id),
    INDEX idx_llm_chain  (session_id, chain_step),
    INDEX idx_llm_route  (session_id, route_type)
);

-- ============================================================
-- App user (minimum privilege, no root in production)
-- Change 'fp3_password' before deploying
-- ============================================================

DROP USER IF EXISTS 'fp3_app'@'localhost';
CREATE USER 'fp3_app'@'localhost' IDENTIFIED BY 'fp3_password';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON fp3.*
    TO 'fp3_app'@'localhost';

FLUSH PRIVILEGES;

-- ============================================================
-- Seed: bootstrap test session for development
-- ============================================================

INSERT INTO sessions (session_token, technical_level)
VALUES (LPAD('0', 64, '0'), 'NON_TECHNICAL');

INSERT INTO domain_state (session_id, domain_json)
VALUES (1, JSON_OBJECT());
