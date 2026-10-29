// Estrutura do banco. Roda quando o servidor sobe: cria o que falta (tabelas, colunas, índices) e grava o
// catálogo de ligas. Funciona num banco vazio e num banco da versão anterior (os dados ficam como estão).
import { createHash } from 'node:crypto'
import { exec, one, q } from './db.ts'
import { LEAGUE_CATALOG } from './leagues.ts'

const SCHEMA_VERSION = 'node-1'

const TABLES = [
  `CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS leagues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    external_id VARCHAR(50) NULL,
    country VARCHAR(12) NOT NULL DEFAULT 'OUT',
    kind ENUM('liga','copa') NOT NULL DEFAULT 'liga',
    short_name VARCHAR(40) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999,
    logo_url VARCHAR(255) NULL,
    season_label VARCHAR(20) NULL,
    season_start DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_full_at INT UNSIGNED NOT NULL DEFAULT 0,
    synced_quick_at INT UNSIGNED NOT NULL DEFAULT 0,
    backfill_day CHAR(8) NULL,
    sync_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    has_live TINYINT(1) NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    espn_id VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(60) NULL,
    abbreviation VARCHAR(10) NULL,
    logo_url VARCHAR(255) NULL,
    color CHAR(6) NULL,
    alt_color CHAR(6) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    league_id INT UNSIGNED NOT NULL,
    round SMALLINT UNSIGNED NOT NULL,
    home_team VARCHAR(100) NOT NULL,
    away_team VARCHAR(100) NOT NULL,
    kickoff_at DATETIME NOT NULL,
    home_score TINYINT UNSIGNED NULL,
    away_score TINYINT UNSIGNED NULL,
    external_id VARCHAR(50) NULL UNIQUE,
    round_label VARCHAR(60) NULL,
    note VARCHAR(60) NULL,
    status VARCHAR(10) NULL,
    status_detail VARCHAR(40) NULL,
    live_home_score TINYINT UNSIGNED NULL,
    live_away_score TINYINT UNSIGNED NULL,
    home_team_id INT UNSIGNED NULL,
    away_team_id INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_league_round (league_id, round),
    INDEX idx_league_kick (league_id, kickoff_at),
    CONSTRAINT fk_matches_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS bolao_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    invite_code VARCHAR(20) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    exact_points TINYINT UNSIGNED NOT NULL DEFAULT 5,
    winner_points TINYINT UNSIGNED NOT NULL DEFAULT 3,
    goal_diff_points TINYINT UNSIGNED NOT NULL DEFAULT 1,
    round_mode ENUM('rodada','duas','livre') NOT NULL DEFAULT 'rodada',
    deadline_mode ENUM('jogo','rodada') NOT NULL DEFAULT 'jogo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groups_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS group_leagues (
    group_id INT UNSIGNED NOT NULL,
    league_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, league_id),
    CONSTRAINT fk_gl_group FOREIGN KEY (group_id) REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_gl_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS group_members (
    group_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner','member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS predictions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    match_id INT UNSIGNED NOT NULL,
    home_pred TINYINT UNSIGNED NOT NULL,
    away_pred TINYINT UNSIGNED NOT NULL,
    points SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prediction (user_id, group_id, match_id),
    CONSTRAINT fk_pred_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pred_group FOREIGN KEY (group_id) REFERENCES bolao_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_pred_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARCHAR(45) NULL,
    created_by ENUM('user','admin') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL,
    INDEX idx_reset_user (user_id, created_at),
    INDEX idx_reset_ip (requested_ip, created_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_attempt_email_ip (email, ip, attempted_at),
    INDEX idx_attempt_ip (ip, attempted_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    last_seen INT UNSIGNED NOT NULL,
    INDEX idx_session_user (user_id),
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS sync_state (
    name VARCHAR(50) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS official_rounds (
    league_id INT UNSIGNED NOT NULL,
    season SMALLINT UNSIGNED NOT NULL,
    home_key VARCHAR(60) NOT NULL,
    away_key VARCHAR(60) NOT NULL,
    round SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (league_id, season, home_key, away_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
]

/** Colunas que bancos de versões bem antigas podem não ter. */
const COLUMNS: Record<string, Record<string, string>> = {
  matches: {
    external_id: 'ADD COLUMN external_id VARCHAR(50) NULL',
    round_label: 'ADD COLUMN round_label VARCHAR(60) NULL',
    note: 'ADD COLUMN note VARCHAR(60) NULL',
    status: 'ADD COLUMN status VARCHAR(10) NULL',
    status_detail: 'ADD COLUMN status_detail VARCHAR(40) NULL',
    live_home_score: 'ADD COLUMN live_home_score TINYINT UNSIGNED NULL',
    live_away_score: 'ADD COLUMN live_away_score TINYINT UNSIGNED NULL',
    home_team_id: 'ADD COLUMN home_team_id INT UNSIGNED NULL',
    away_team_id: 'ADD COLUMN away_team_id INT UNSIGNED NULL',
  },
  leagues: {
    country: "ADD COLUMN country VARCHAR(12) NOT NULL DEFAULT 'OUT'",
    kind: "ADD COLUMN kind ENUM('liga','copa') NOT NULL DEFAULT 'liga'",
    short_name: 'ADD COLUMN short_name VARCHAR(40) NULL',
    sort_order: 'ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999',
    logo_url: 'ADD COLUMN logo_url VARCHAR(255) NULL',
    season_label: 'ADD COLUMN season_label VARCHAR(20) NULL',
    season_start: 'ADD COLUMN season_start DATE NULL',
    is_active: 'ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
    synced_full_at: 'ADD COLUMN synced_full_at INT UNSIGNED NOT NULL DEFAULT 0',
    synced_quick_at: 'ADD COLUMN synced_quick_at INT UNSIGNED NOT NULL DEFAULT 0',
    backfill_day: 'ADD COLUMN backfill_day CHAR(8) NULL',
    sync_failures: 'ADD COLUMN sync_failures TINYINT UNSIGNED NOT NULL DEFAULT 0',
    has_live: 'ADD COLUMN has_live TINYINT(1) NOT NULL DEFAULT 0',
  },
  bolao_groups: {
    round_mode: "ADD COLUMN round_mode ENUM('rodada','duas','livre') NOT NULL DEFAULT 'rodada'",
    deadline_mode: "ADD COLUMN deadline_mode ENUM('jogo','rodada') NOT NULL DEFAULT 'jogo'",
  },
}

export async function getState(): Promise<Record<string, string>> {
  const rows = await q<{ name: string; value: string }>('SELECT name, value FROM sync_state')
  return Object.fromEntries(rows.map((r) => [r.name, r.value]))
}

export async function setState(values: Record<string, string | number>) {
  for (const [name, value] of Object.entries(values)) {
    await exec('INSERT INTO sync_state (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [name, String(value)])
  }
}

export async function migrate() {
  // Trava entre processos: dois servidores subindo juntos não alteram as tabelas ao mesmo tempo.
  await one("SELECT GET_LOCK('bolao_migrate', 30)")
  try {
    await exec(TABLES[TABLES.length - 2]) // sync_state primeiro
    const state = await getState()
    if (state.schema !== SCHEMA_VERSION) {
      for (const sql of TABLES) await exec(sql)
      for (const [table, wanted] of Object.entries(COLUMNS)) {
        const have = new Set(
          (await q<{ c: string }>('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [table])).map((r) => r.c),
        )
        const changes = Object.entries(wanted).filter(([col]) => !have.has(col)).map(([, sql]) => sql)
        if (changes.length) await exec(`ALTER TABLE ${table} ${changes.join(', ')}`)
      }
      const idx = await one("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND INDEX_NAME = 'idx_league_kick'")
      if (!idx?.n) await exec('ALTER TABLE matches ADD INDEX idx_league_kick (league_id, kickoff_at)')
      await setState({ schema: SCHEMA_VERSION })
    }
    await seedLeagues(state.catalog ?? '')
  } finally {
    await one("SELECT RELEASE_LOCK('bolao_migrate')")
  }
}

/** Grava o catálogo na tabela leagues, só quando ele muda. Ligas cadastradas à mão ficam como estão. */
async function seedLeagues(storedHash: string) {
  const hash = createHash('md5').update(JSON.stringify(LEAGUE_CATALOG)).digest('hex')
  if (hash === storedHash) return
  for (const [code, l] of Object.entries(LEAGUE_CATALOG)) {
    await exec(
      `INSERT INTO leagues (name, code, external_id, country, kind, short_name, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE name = VALUES(name), external_id = VALUES(external_id), country = VALUES(country),
         kind = VALUES(kind), short_name = VALUES(short_name), sort_order = VALUES(sort_order)`,
      [l.name, code, l.slug, l.country, l.kind, l.short, l.sort],
    )
  }
  await setState({ catalog: hash })
}
