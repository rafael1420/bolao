-- Bolão - estrutura do banco de dados (MySQL / MariaDB, Hostinger)
-- Execute este arquivo uma vez no phpMyAdmin do seu banco.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leagues (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    code        VARCHAR(20)  NOT NULL UNIQUE,
    external_id VARCHAR(50)  NULL,         -- slug da liga na ESPN (ex.: bra.1)
    country     VARCHAR(12)  NOT NULL DEFAULT 'OUT', -- país ou região (COUNTRIES em includes/leagues.php)
    kind        ENUM('liga','copa') NOT NULL DEFAULT 'liga',
    short_name  VARCHAR(40)  NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 999,
    logo_url    VARCHAR(255) NULL,
    season_label VARCHAR(20) NULL,         -- ex.: "2026-27"
    season_start DATE        NULL,         -- início da temporada atual (jogos anteriores ficam fora das rodadas)
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    synced_full_at  INT UNSIGNED NOT NULL DEFAULT 0, -- controle da sincronização, por liga
    synced_quick_at INT UNSIGNED NOT NULL DEFAULT 0,
    backfill_day    CHAR(8)  NULL,
    sync_failures   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    has_live        TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Times (ESPN): nome, sigla, escudo e cores. Os jogos guardam também o nome em texto.
CREATE TABLE IF NOT EXISTS teams (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    espn_id      VARCHAR(20)  NOT NULL UNIQUE,
    name         VARCHAR(100) NOT NULL,
    short_name   VARCHAR(60)  NULL,
    abbreviation VARCHAR(10)  NULL,
    logo_url     VARCHAR(255) NULL,
    color        CHAR(6)      NULL,
    alt_color    CHAR(6)      NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    league_id    INT UNSIGNED NOT NULL,
    round        SMALLINT UNSIGNED NOT NULL,
    home_team    VARCHAR(100) NOT NULL,
    away_team    VARCHAR(100) NOT NULL,
    kickoff_at   DATETIME NOT NULL,
    home_score   TINYINT UNSIGNED NULL,     -- NULL = jogo ainda não terminou
    away_score   TINYINT UNSIGNED NULL,
    external_id  VARCHAR(50) NULL UNIQUE,   -- id do jogo na ESPN (sincronização automática)
    round_label  VARCHAR(60) NULL,          -- nome da fase nas copas ("Semifinal"); NULL = "Rodada N"
    note         VARCHAR(60) NULL,          -- ex.: "Jogo de ida"
    status       VARCHAR(10) NULL,          -- ESPN: pre, in (ao vivo), post, void (adiado/cancelado)
    status_detail VARCHAR(40) NULL,         -- ex.: "45'+2'"
    live_home_score TINYINT UNSIGNED NULL,  -- placar parcial durante o jogo
    live_away_score TINYINT UNSIGNED NULL,
    home_team_id INT UNSIGNED NULL,         -- teams.id (NULL em jogo cadastrado à mão)
    away_team_id INT UNSIGNED NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_league_round (league_id, round),
    INDEX idx_league_kick (league_id, kickoff_at),
    CONSTRAINT fk_matches_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bolao_groups (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    owner_id         INT UNSIGNED NOT NULL,
    invite_code      VARCHAR(20)  NOT NULL UNIQUE,
    password_hash    VARCHAR(255) NULL,       -- senha opcional para entrar no grupo
    exact_points     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    winner_points    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    goal_diff_points TINYINT UNSIGNED NOT NULL DEFAULT 1,
    round_mode       ENUM('rodada','duas','livre') NOT NULL DEFAULT 'rodada', -- quais rodadas aceitam palpite
    deadline_mode    ENUM('jogo','rodada') NOT NULL DEFAULT 'jogo',          -- prazo: cada jogo ou 1º jogo da rodada
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groups_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_leagues (
    group_id  INT UNSIGNED NOT NULL,
    league_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, league_id),
    CONSTRAINT fk_gl_group  FOREIGN KEY (group_id)  REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_gl_league FOREIGN KEY (league_id) REFERENCES leagues(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
    group_id  INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    role      ENUM('owner','member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_user  FOREIGN KEY (user_id)  REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS predictions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    group_id   INT UNSIGNED NOT NULL,
    match_id   INT UNSIGNED NOT NULL,
    home_pred  TINYINT UNSIGNED NOT NULL,
    away_pred  TINYINT UNSIGNED NOT NULL,
    points     SMALLINT UNSIGNED NULL,      -- preenchido quando o jogo termina
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prediction (user_id, group_id, match_id),
    CONSTRAINT fk_pred_user  FOREIGN KEY (user_id)  REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_pred_group FOREIGN KEY (group_id) REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_pred_match FOREIGN KEY (match_id) REFERENCES matches(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links de "esqueci minha senha". Guarda só o hash (SHA-256) do token.
CREATE TABLE IF NOT EXISTS password_resets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL UNIQUE,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,           -- uso único
    requested_ip VARCHAR(45)  NULL,
    created_by   ENUM('user','admin') NOT NULL DEFAULT 'user',
    created_at   DATETIME     NOT NULL,
    INDEX idx_reset_user (user_id, created_at),
    INDEX idx_reset_ip (requested_ip, created_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login que falharam (limite por e-mail + IP).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(150) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL,
    INDEX idx_attempt_email_ip (email, ip, attempted_at),
    INDEX idx_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle da sincronização automática (includes/sync.php). Ela também cria esta tabela sozinha.
CREATE TABLE IF NOT EXISTS sync_state (
    name  VARCHAR(50) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rodada oficial de cada confronto das ligas com dados no TheSportsDB. Também criada pela sincronização.
CREATE TABLE IF NOT EXISTS official_rounds (
    league_id INT UNSIGNED NOT NULL,
    season    SMALLINT UNSIGNED NOT NULL,
    home_key  VARCHAR(60) NOT NULL,
    away_key  VARCHAR(60) NOT NULL,
    round     SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (league_id, season, home_key, away_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liga inicial (as demais o site cria sozinho a partir do catálogo, includes/leagues.php)
INSERT IGNORE INTO leagues (name, code, external_id, country, kind, sort_order, short_name)
VALUES ('Brasileirão Série A', 'BRA-A', 'bra.1', 'BRA', 'liga', 10, 'Série A');
