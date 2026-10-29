-- Migração 002: catálogo de ligas por país, times (escudos e cores) e modo de rodada dos bolões.
-- Para quem já tem o banco instalado. Pode ser importada mais de uma vez sem problema.
-- phpMyAdmin: selecione o banco, aba Importar, escolha este arquivo e clique em Executar.
-- Funciona no MySQL 5.7 e no MariaDB: cada coluna só é criada se ainda não existir.

SET NAMES utf8mb4;

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

-- Adiciona coluna só se faltar
SET @db = DATABASE();

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='country')=0,
  'ALTER TABLE leagues ADD COLUMN country VARCHAR(12) NOT NULL DEFAULT ''OUT''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='kind')=0,
  'ALTER TABLE leagues ADD COLUMN kind ENUM(''liga'',''copa'') NOT NULL DEFAULT ''liga''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='short_name')=0,
  'ALTER TABLE leagues ADD COLUMN short_name VARCHAR(40) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='sort_order')=0,
  'ALTER TABLE leagues ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='logo_url')=0,
  'ALTER TABLE leagues ADD COLUMN logo_url VARCHAR(255) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='season_label')=0,
  'ALTER TABLE leagues ADD COLUMN season_label VARCHAR(20) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='season_start')=0,
  'ALTER TABLE leagues ADD COLUMN season_start DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='is_active')=0,
  'ALTER TABLE leagues ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leagues' AND COLUMN_NAME='synced_full_at')=0,
  'ALTER TABLE leagues ADD COLUMN synced_full_at INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN synced_quick_at INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN backfill_day CHAR(8) NULL, ADD COLUMN sync_failures TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN has_live TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='matches' AND COLUMN_NAME='home_team_id')=0,
  'ALTER TABLE matches ADD COLUMN home_team_id INT UNSIGNED NULL, ADD COLUMN away_team_id INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='matches' AND INDEX_NAME='idx_league_kick')=0,
  'ALTER TABLE matches ADD INDEX idx_league_kick (league_id, kickoff_at)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='bolao_groups' AND COLUMN_NAME='round_mode')=0,
  'ALTER TABLE bolao_groups ADD COLUMN round_mode ENUM(''rodada'',''duas'',''livre'') NOT NULL DEFAULT ''rodada'', ADD COLUMN deadline_mode ENUM(''jogo'',''rodada'') NOT NULL DEFAULT ''jogo''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Ligas que já existiam: país, tipo e ordem (as demais o site cria sozinho a partir do catálogo)
UPDATE leagues SET external_id='bra.1', country='BRA', kind='liga', sort_order=10, short_name='Série A' WHERE code='BRA-A';
UPDATE leagues SET external_id='bra.copa_do_brazil', country='BRA', kind='copa', sort_order=40, short_name='Copa do Brasil' WHERE code='BRA-CDB';
UPDATE leagues SET external_id='conmebol.libertadores', country='CONMEBOL', kind='copa', sort_order=10, short_name='Libertadores' WHERE code='CONMEBOL-LIB';
