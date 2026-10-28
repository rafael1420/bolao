-- Migração 001: recuperação de senha e limite de tentativas de login.
-- Para quem já tem o banco instalado. Pode ser importada mais de uma vez sem problema.
-- phpMyAdmin: selecione o banco, aba Importar, escolha este arquivo e clique em Executar.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL UNIQUE,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,
    requested_ip VARCHAR(45)  NULL,
    created_by   ENUM('user','admin') NOT NULL DEFAULT 'user',
    created_at   DATETIME     NOT NULL,
    INDEX idx_reset_user (user_id, created_at),
    INDEX idx_reset_ip (requested_ip, created_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(150) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL,
    INDEX idx_attempt_email_ip (email, ip, attempted_at),
    INDEX idx_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
