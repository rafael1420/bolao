# Implementation plan: Conferência Bolão (more leagues, team logos, round-by-round play, new look)

I read the code and changed nothing. Things I checked directly:
- About 75 ESPN scoreboard slugs, probed with curl on 2026-10-28.
- ESPN team logos and colors, and the resized-logo service.
- Whether ESPN accepts date ranges. It does not: every range returned HTTP 400.
- TheSportsDB round lookups for the Premier League and LaLiga. They work.
- WCAG contrast ratios for the proposed palette.

**There is no PHP, MySQL/MariaDB or Docker on this Mac.** Nothing in this plan has been run. `includes/config.php` points at `127.0.0.1/bolao_dev`. Before starting, run `brew install php mariadb` (or use XAMPP) so each task can be checked with `php -l` and `php -S 127.0.0.1:8080`.

---

## 1. Current architecture (what these changes touch)

**Database (`sql/schema.sql`)**
- `leagues` has only `id`, `name`, `code`, `external_id` (lines 14–19).
- `matches` stores team names as plain text in `home_team` / `away_team` and the round in `round`, plus `round_label`, `note`, `status`, live scores and `external_id` (lines 21–40). There is no team table.
- `bolao_groups` holds only the scoring settings (lines 42–52).
- `group_leagues` links groups to leagues. `predictions` has a unique key on (user, group, match).
- `sync_state` and `official_rounds` are at lines 112–127.

**Sync (`includes/sync.php`)**
- The league list is hard-coded in `SYNC_LEAGUES` (lines 9–13): `bra.1`, `bra.copa_do_brazil`, `conmebol.libertadores`.
- `sync_schedule()` (60–93) runs the sync after the page is sent, inside a shutdown function using `litespeed_finish_request` / `fastcgi_finish_request`.
- `sync_due()` (96–109) decides between a full sync every 6 h and a quick one every 5 min (1 min when a game is live). **These timers are global, not per league.**
- `sync_migrate()` (132–183) is the self-migration, gated on `SYNC_SCHEMA = '2'` stored in `sync_state`. It adds `matches` columns and `official_rounds`.
- `sync_leagues()` (186–201) inserts any missing league, matched by code.
- `sync_run()` (207–298):
  - takes `GET_LOCK('bolao_sync')`;
  - fetches the default scoreboard for every league, then one request per day: yesterday, today, tomorrow and pending days;
  - in full mode it also fetches **every calendar day of the season** for leagues, and −10 to +60 days for cups;
  - this cost grows linearly with the number of leagues.
- `sync_save_events()` (330–387) upserts by `external_id` and calls `recalculate_match()` when a score changes.
- `espn_parse_event()` (390–455):
  - reads only `team.displayName`, **so logo, colors, abbreviation and team id are thrown away**;
  - takes the cup stage from the calendar `entries` date ranges and labels it via `CUP_STAGES` (38–54);
  - reads first/second leg from `notes[0].headline`.
- Round numbers for the Brasileirão:
  - `sync_official_rounds()` (458–476) reads TheSportsDB, which is hard-wired to league 4351 and capped at 38 rounds;
  - `sync_number_rounds()` (512–571) applies them, falling back to the nearest-date official round;
  - `sync_guess_rounds()` (579–649) estimates rounds from the fixture list when no official data exists.
- `espn_fetch_many()` (660–698) uses curl_multi in batches of 15, with a 25 s timeout per request.

**Shared rules (`includes/app.php`)**
- `sync_schedule()` is called on every page (line 12).
- `is_open()` (47–56) is purely time-based. **This is the only gate today.**
- Other helpers: `round_label()` (59), `is_void()`, `not_void_sql()`.
- `group_rankings()` (282–333) can already filter by round and league.
- `closed_match_predictions()` (371–434) shows other members' picks only once `!is_open`.
- `group_scored_rounds()` (441–468) feeds the ranking round picker.
- The password-reset email uses `SITE_NAME` (line 642).

**Group page (`group.php`)**
- Tabs are fixed (12–14): palpites / ranking / participantes / configurar|regras.
- League choice (35–44): the requested one, else the league with the next game.
- It loads every match of that league (47–55) and builds `$rounds`, `$roundLabels`, `$roundStats` (64–95).
- Default round (98–101): the lowest round with an unfinished game from the last 3 days.
- Rankings (107–134).

**Group views (`includes/group_views.php`)**
- `group_header()` (33–81) draws the header and tabs.
- `round_state()` (84–96) gives each round chip its state.
- `render_palpites()` (99–419) draws the league tabs, round strip, round header, match cards (team crests at 292 and 349) and the save bar.
- `render_ranking()` (422–495) has a podium and the "Geral / Rodada" selector (`render_ranking_selector()`, 816–843).
- `render_configurar()` (584–736): **the league picker (589, 652–669) is a flat, alphabetical checkbox list.**
- `render_regras()` (739–778), `render_league_tabs()` (903–917), `render_round_jump()` (920–939).

**Saving picks (`predict.php`)**
- It loads the matches of one league and round (23–27) and accepts a pick only when `is_open()` is true (37). There is no round gating.

**Creating and configuring groups**
- `create_group.php` shows a flat league list (5, 120–123).
- `group_settings.php` handles the league change (67–88): delete, re-insert, then `recalculate_group`.

**Home page (`index.php`)**
- The "pending picks" and "next game" counts (30–56) count **every** future match in the group's leagues. With round gating they must count only matches that can actually be predicted.

**Layout (`includes/layout.php`)**
- `asset()` (5–9) already adds `?v=filemtime`, so CSS/JS cache busting already works.
- `team_initials()` (101–123) and `team_crest()` (131–165) draw a hash-colored SVG crest from the team name.
- `brand_mark()` (53–66) is the yellow ball logo.
- `check_option()` (411–417) renders a checkbox.
- `SITE_NAME` appears at 174–175, 235–236 and 265.

**Admin (`admin_matches.php`)**
- "Sincronizar agora" calls `sync_run('full')` (37–57).
- The sync panel text is hard-coded to three leagues (348).
- Team crests at 418 and 636.

**Installer, assets and docs**
- `install.php` (22, 39) defaults the site name to 'Bolão'. `$migrationTables` is at 33–36.
- `assets/manifest.webmanifest` has name "Bolão".
- `assets/favicon.svg` and the PNG icons use the yellow ball.
- `assets/style.css`: light tokens at 16–80 and dark tokens at 83–157, written twice (media query and `[data-theme]`). About 30 hard-coded yellow or navy values remain between lines 366 and 1609.
- `assets/app.js` has the countdown (`li.match[data-kick]`, `time[data-kickoff]`, `time[data-first]`), the save bar, `data-autosubmit` and the dirty-form guard.

---

## 2. Verified ESPN slugs, grouped by country or region

All were tested via `https://site.api.espn.com/apis/site/v2/sports/soccer/<slug>/scoreboard`. "liga" means the calendar is a list of days; "copa" means a list of stages.

| Code | Slug | Name (pt-BR) | Type | ESPN season, 28/10/2026 |
|---|---|---|---|---|
| **Brasil (BRA)** | | | | |
| BRA-A | bra.1 | Brasileirão Série A | liga | 2026, 5 games |
| BRA-B | bra.2 | Brasileirão Série B | liga | 2026 |
| BRA-C | bra.3 | Brasileirão Série C | liga | *2025, finals (off-season)* |
| BRA-CDB | bra.copa_do_brazil | Copa do Brasil | copa | 2026, semifinals |
| BRA-NE | bra.copa_do_nordeste | Copa do Nordeste | copa | 2026, over |
| BRA-SP | bra.camp.paulista | Paulistão | liga | 2026, over (Jan–Apr) |
| BRA-RJ | bra.camp.carioca | Carioca | liga | 2026, over |
| BRA-MG | bra.camp.mineiro | Mineiro | liga | 2026, over |
| BRA-RS | bra.camp.gaucho | Gauchão | liga | 2026, over |
| **América do Sul (CONMEBOL)** | | | | |
| CONMEBOL-LIB | conmebol.libertadores | Libertadores | copa | 2026, final |
| CONMEBOL-SUD | conmebol.sudamericana | Sul-Americana | copa | 2026, final |
| CONMEBOL-REC | conmebol.recopa | Recopa Sul-Americana | copa | 2026 |
| **Europa (UEFA)** | | | | |
| UEFA-UCL | uefa.champions | Champions League | copa | 2026-27, league phase |
| UEFA-UEL | uefa.europa | Liga Europa | copa | 2026-27 |
| UEFA-UECL | uefa.europa.conf | Liga Conferência | copa | 2026-27 |
| UEFA-SUP | uefa.super_cup | Supercopa da UEFA | copa | 2026 |
| UEFA-NL | uefa.nations | Liga das Nações | copa | 2026-27 |
| **Seleções e Mundial (FIFA)** | | | | |
| FIFA-WC | fifa.world | Copa do Mundo | copa | 2026, over |
| FIFA-CWC | fifa.cwc | Mundial de Clubes | copa | *2025* |
| FIFA-WCQ-SA | fifa.worldq.conmebol | Eliminatórias (América do Sul) | copa | 2026 cycle |
| FIFA-WCQ-EU | fifa.worldq.uefa | Eliminatórias (Europa) | copa | 2026 |
| FIFA-AMI | fifa.friendly | Amistosos internacionais | liga | 2026 |
| *(off-season)* | conmebol.america / uefa.euro | Copa América / Eurocopa | copa | 2024 |
| **Inglaterra (ENG)** | | | | |
| ENG-1 | eng.1 | Premier League | liga | 2026-27 |
| ENG-2 | eng.2 | Championship | liga | 2026-27 |
| ENG-3 | eng.3 | League One | liga | 2026-27 |
| ENG-FA | eng.fa | Copa da Inglaterra (FA Cup) | copa | 2026-27 |
| ENG-EFL | eng.league_cup | Copa da Liga Inglesa | copa | 2026-27 |
| **Espanha (ESP)** | | | | |
| ESP-1 | esp.1 | LaLiga | liga | 2026-27 |
| ESP-2 | esp.2 | LaLiga 2 | liga | 2026-27 |
| ESP-CR | esp.copa_del_rey | Copa do Rei | copa | 2026-27 |
| **Itália (ITA)** | | | | |
| ITA-1 | ita.1 | Campeonato Italiano (Série A) | liga | 2026-27 |
| ITA-2 | ita.2 | Série B Italiana | liga | 2026-27 |
| ITA-CI | ita.coppa_italia | Copa da Itália | copa | 2026-27 |
| **Alemanha (GER)** | | | | |
| GER-1 | ger.1 | Bundesliga | liga | 2026-27 |
| GER-2 | ger.2 | 2. Bundesliga | liga | 2026-27 |
| GER-CUP | ger.dfb_pokal | Copa da Alemanha | copa | 2026-27 |
| **França (FRA)** | | | | |
| FRA-1 | fra.1 | Ligue 1 | liga | 2026-27 |
| FRA-2 | fra.2 | Ligue 2 | liga | 2026-27 |
| FRA-CUP | fra.coupe_de_france | Copa da França | copa | *2025-26 (not started)* |
| **Portugal (POR)** | | | | |
| POR-1 | por.1 | Liga Portugal | liga | 2026-27 |
| POR-TP | por.taca.portugal | Taça de Portugal | copa | 2026-27 |
| **Holanda (NED)** | | | | |
| NED-1 | ned.1 | Eredivisie | liga | 2026-27 |
| NED-CUP | ned.cup | Copa da Holanda | copa | 2026-27 |
| **Argentina (ARG)** | | | | |
| ARG-1 | arg.1 | Liga Profissional Argentina | liga | 2026 Clausura |
| ARG-CUP | arg.copa | Copa Argentina | copa | 2026 |
| **Other South American countries (in the "Mais países" fold)** | | | | |
| URU-1 | uru.1 | Campeonato Uruguaio | liga | 2026 |
| COL-1 | col.1 | Campeonato Colombiano | liga | 2026 |
| CHI-1 | chi.1 | Campeonato Chileno | liga | 2026 |
| PAR-1 | par.1 | Campeonato Paraguaio | liga | 2026 |
| PER-1 | per.1 | Campeonato Peruano | liga | 2026 |
| ECU-1 | ecu.1 | Campeonato Equatoriano | liga | 2026 |
| **América do Norte** | | | | |
| USA-1 | usa.1 | MLS | liga | 2026 |
| MEX-1 | mex.1 | Liga MX | liga | 2026-27 Apertura |
| CONCACAF-CC | concacaf.champions | Copa dos Campeões da Concacaf | copa | 2026 |
| **Other countries (in the "Mais países" fold)** | | | | |
| SCO-1 | sco.1 | Campeonato Escocês | liga | 2026-27 |
| TUR-1 | tur.1 | Campeonato Turco | liga | 2026-27 |
| BEL-1 | bel.1 | Campeonato Belga | liga | 2026-27 |
| KSA-1 | ksa.1 | Campeonato Saudita | liga | 2026-27 |
| JPN-1 | jpn.1 | J1 League (Japão) | liga | 2026 |

**Also working, but left out of the first catalog:** aus.1, gre.1, sui.1 (still on 2025-26), aut.1.

**Do not use:**
- `sau.1` returns 400. The Saudi league is `ksa.1`.
- `bra.copa_do_brasil` returns 400. The correct slug is `bra.copa_do_brazil`.
- `bra.camp.paranaense`, `bra.camp.baiano`, `bra.camp.pernambucano` return 400.
- `bra.4` has no events and no calendar type.
- Date ranges (`?dates=YYYYMMDD-YYYYMMDD`) return 400 on every league. **Single days are the only option.**

**What ESPN gives us, and what it doesn't**
- Each event's `competitors[].team` includes `id`, `displayName`, `shortDisplayName`, `abbreviation`, `color` (hex without `#`), `alternateColor` and `logo`. Example: Chelsea, id 363, CHE, 144992 / FFFFFF, `https://a.espncdn.com/i/teamlogos/soccer/500/363.png`.
- The 500 px PNG is about 60 KB. `https://a.espncdn.com/combiner/i?img=/i/teamlogos/soccer/500/363.png&w=64&h=64` returns 200 at about 6.5 KB.
- League logos come from `leagues[0].logos[0].href`.
- **ESPN events have no `week` or matchday field** for any league. Round numbers therefore have to come from TheSportsDB or from the existing estimate.
- TheSportsDB `eventsround.php` works for the Premier League (`id=4328&s=2026-2027`) and LaLiga (4335). Brasileirão is `id=4351&s=2026`.
- For European seasons the season string is `YYYY-YYYY`, so the catalog needs a season format per league.
- UCL stages read: League Phase (1), Knockout Round Playoffs (2), Rd of 16 (3), Quarterfinals (4), Semifinals (5), Final (6). **The league phase spans August to February.** Cup stages therefore need splitting into matchdays (see §4).

---

## 3. Data model and migration 002

**Single source of truth: a new `includes/leagues.php`.** It holds:
- `const COUNTRIES = ['BRA' => ['Brasil', 10, true], 'CONMEBOL' => ['América do Sul', 20, true], 'UEFA' => ['Europa', 30, true], 'FIFA' => ['Seleções e Mundial', 40, true], 'ENG' => ['Inglaterra', 50, true], … 'URU' => ['Uruguai', 200, false], …]`. The fields are name, sort order and `featured`; non-featured countries go into the "Mais países" fold.
- `const LEAGUE_CATALOG = ['BRA-A' => ['slug' => 'bra.1', 'name' => 'Brasileirão Série A', 'short' => 'Série A', 'country' => 'BRA', 'kind' => 'liga', 'sort' => 10, 'tsdb' => [4351, 'Y']], …]`. The `tsdb` entry is optional; `'Y'` means season "2026" and `'Y-Y'` means "2026-2027".
- `country_flag_svg(string $code)`: small original inline SVG flags.
  - Simple stripes cover Itália, Alemanha, França, Holanda, Bélgica, Colômbia, Argentina and México.
  - Simple shapes cover Brasil (diamond and circle), Inglaterra (cross), Escócia (saltire), Japão (disc), Espanha, Portugal and EUA (simplified).
  - CONMEBOL, UEFA and FIFA get a globe or trophy icon.
  - **Why not alternatives:** emoji flags show as letters on Windows, and the England flag emoji is barely supported. ESPN's country images for ita, ger and bel all return the same ~3.3 KB placeholder.
- `leagues_seed()`: upserts the catalog into `leagues`. It only runs when `sync_state.catalog` differs from `md5(serialize(LEAGUE_CATALOG))`, so the cost is one cheap comparison per request.
- `SYNC_LEAGUES` in sync.php is replaced by reads from the `leagues` table.

**Countries stay as a PHP constant, not a table.** They change only with a code deploy, so a table would add a join and admin UI for no benefit. `leagues.country` stores the code.

**Teams get their own table**, keyed by ESPN team id. Matches gain nullable `home_team_id` / `away_team_id`. The text columns stay, because they are needed for manual or imported matches and for the TheSportsDB name keys. No foreign keys on the team ids: teams are never deleted, and it keeps the self-migration simple.

### `sql/migrations/002_ligas_times_rodadas.sql`
This works on MySQL 5.7 and MariaDB, and is safe to import more than once. MySQL 5.7 has no `ADD COLUMN IF NOT EXISTS`, so each change is wrapped in a check against information_schema plus `PREPARE`. phpMyAdmin runs it without `DELIMITER`.

```sql
-- Migração 002: catálogo de ligas por país, times (escudos e cores), modo de rodada dos bolões.
-- Pode ser importada mais de uma vez. phpMyAdmin: selecione o banco, aba Importar, Executar.
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
```

**`external_id` becomes the ESPN slug column.** `sync_leagues()` already writes the slug there (sync.php:195). No separate slug column is needed.

**Update `sql/schema.sql`:**
- Add the new columns to the `CREATE TABLE` for `leagues`, `matches` and `bolao_groups`, plus the `teams` table and `INDEX idx_league_kick`.
- The seed insert becomes `INSERT IGNORE INTO leagues (name, code, external_id, country, kind, sort_order) VALUES ('Brasileirão Série A','BRA-A','bra.1','BRA','liga',10);`
- Update the README table count from 11 to 12, and add `'teams' => '002_…'` to `install.php`'s `$migrationTables`.

**Extend the self-migration: yes, with a change to where it runs.**
- Raise `SYNC_SCHEMA` to `'3'`. Make `sync_migrate()` add the same columns and table, using its existing information_schema pattern for `leagues`, `matches`, `bolao_groups` and `teams`.
- **Today it only runs inside the after-response sync** (sync_run, line 215). The new pages read `leagues.country` and `bolao_groups.round_mode` while rendering, so a deploy without the migration would fail on the first page.
- **Add `ensure_schema()` in app.php**, called before `sync_schedule()`. It reads `sync_state.schema` (one query); if outdated, it runs `sync_migrate()` under `GET_LOCK('bolao_migrate', 5)`, then `leagues_seed()`.
- The migration file remains the documented path. The self-migration is the safety net.

---

## 4. Round rules (new `includes/rounds.php`, pure functions so they can be tested without a database)

**Round numbers**
- **Leagues with TheSportsDB data** use the official round from TheSportsDB. This generalizes `sync_official_rounds()` / `sync_number_rounds()`:
  - loop over leagues that have `tsdb`, instead of the hard-coded 4351;
  - set `TSDB_ROUNDS` and games per round per league, from team count × 2 − 2 and team count / 2;
  - keep the 10-minute throttle and the limit of 3 rounds per run, applied globally across leagues;
  - keep the team-name aliases in `TEAM_ALIASES`; the existing nearest-date fallback covers names that don't match.
  - Candidates: bra.1 (4351), eng.1 (4328), esp.1 (4335). Look up ita.1 (4332), ger.1 (4331), fra.1 (4334), por.1 (4344) and ned.1 (4337) with one curl each during implementation; I only tested 4328, 4335 and 4351.
- **Other leagues** use `sync_guess_rounds()` over the full season. The first time a league is activated, a backfill walks its calendar from the start, 30 days per run, tracked in `leagues.backfill_day`. **Without the backfill, "Rodada 1" would be the first day that happened to be synced.** MLS and the state championships get estimated rounds; that is acceptable.
- **Cups:** round = stage × 20 + sequence. Add a new `sync_number_cup_rounds($leagueId)`:
  - inside each stage, if `note` says first or second leg, the sequence is 1 or 2 and the label is "Oitavas de final · ida/volta";
  - otherwise `sync_guess_rounds()` runs on that stage's games, which splits the UCL "Fase de liga" into "Fase de liga · Rodada 1…8" and the Libertadores group stage into 6 rounds;
  - single-match stages get sequence 1 and keep the plain label.
  - Extend `CUP_STAGES` with: League Phase → "Fase de liga", Knockout Round Playoffs → "Playoffs", Round of 16 / Round of 32 → "Oitavas de final" / "16 avos de final", Finals → "Final". Add a regex fallback `(\d+)(st|nd|rd|th) Round` → "Nª fase".
  - **Changing existing cup round numbers is safe:** picks are linked by `match_id`, and stale `?round=` links fall back to the current round.

**The current round, per league, built by `league_rounds(array $matches, int $now): array`**
1. Only games that aren't postponed or cancelled (`status = 'void'`) count. They are already excluded from picks, pending counts and rankings, and stay that way.
2. For each round, take the median kickoff. **Core games** are those within ±4 days of the median. The others are **rescheduled games**: postponed and replayed weeks later, or played early. The round's start and end are the first and last core kickoff.
3. A round is **finished** when every core game has a final score, or kicked off more than 3 days ago with no score. The 3-day rule matches today's `$recent` logic (group.php:67–90), so one stuck game can't freeze the bolão.
4. The **current round C** is the round with the earliest start among unfinished rounds. If none is unfinished, the season is over: C is the last round, everything is read-only, and a "Temporada encerrada" banner shows.
5. The **next round N** is the round after C in start order. The arrows and chip strip use round-number order, which is chronological for cups.
6. **A round's games may be spread over several days.** That is handled naturally: the round stays current until all core games are finished. Midweek rounds that overlap are handled by the 48 h rule below.

**Which rounds are open, per group (`bolao_groups.round_mode`)**
- **`rodada` (default for new and existing groups):**
  - C is open while it still has games that haven't started.
  - N opens once **all of C's core games have kicked off**, or once **N.start ≤ now + 48 h** (back-to-back rounds or midweek rounds).
  - Shown as: "Abre em" min(C.end, N.start − 48 h).
  - Rounds after N show "Abre depois da Rodada X" and stay locked.
- **`duas`:** C and N are always open; N+1 opens when C has no more unstarted games.
- **`livre`:** today's behavior, open until each game starts. It is the escape hatch for anyone who prefers the old way.
- **Rescheduled games** can be predicted when their kickoff ≤ H, where H = the end of the latest open round + 24 h. In other words, they open in the week they are actually played. They also appear in a "Jogos remarcados" section of the current-round view, with the note "da Rodada 5".
- **`can_predict(match, ctx)`** is true when all of these hold:
  - `is_open(match)` (time and status, unchanged);
  - the round is open, or the game is a rescheduled game inside H, or the mode is `livre`;
  - if `deadline_mode = 'rodada'`, the round's first core kickoff is still in the future.
- **`deadline_mode`:**
  - `jogo` (default) keeps today's per-game deadline.
  - `rodada` (Cartola style) closes the whole round at its first kickoff, and the header shows "palpites abertos até sáb, 16h".
- **Privacy:** other members' picks are still revealed only after kickoff (`closed_match_predictions()` keeps using `is_open`). Games in future rounds that are still locked are never revealed.

**Round states for the UI**
- `encerrada`: past, read-only, shows results and points.
- `aguardando`: every game has started, results are pending.
- `aberta`: shows the countdown to the next deadline.
- `bloqueada`: future, with "Abre em …" or "Abre depois da Rodada X".

**Server-side enforcement**
- **`predict.php`** loads all of the league's non-void matches, so it can build the round context with the same function.
- For each submitted pick it calls `can_predict()`. New reasons it can give:
  - "a rodada ainda não abriu (abre {qui, 19h})";
  - "o prazo da rodada já passou";
  - the existing "o jogo já começou" / "já terminou".
- Picks already saved in future rounds stay stored, are shown read-only and become editable when the round opens.
- **Shared helper `round_context(int $leagueId, array $group, int $now)`** is cached per request and used by group.php, predict.php and index.php.
- **index.php** replaces the two SQL counts (30–56) with `group_predictable(array $groups)`. It loads matches of the leagues involved within kickoff [now − 20 d, now + 90 d] in one query, builds each league context once, and counts only predictable games that have no pick. "Próximo prazo" becomes the next closing time of a predictable game.

---

## 5. Group page structure, inspired by Cartola's general flow (no Cartola branding)

Tabs in `group_header()`, configured in group.php:12–14:

1. **Rodada** (`tab=rodada`, the default; the old `palpites` key is kept as an alias):
   - A **league selector**: chips with a flag, a mini league logo and the short name. It appears when the group has more than one league. It reworks `render_league_tabs()`.
   - A **round header card**, new `render_round_banner()`:
     - prev/next arrows around a big round title ("Rodada 12", "Oitavas de final · ida");
     - a status line, e.g. "Palpites abertos · próximo fechamento sáb, 16h" with a `time[data-first]` countdown (already driven by app.js), "Palpites fechados · aguardando resultados", "Encerrada · você fez 14 pts · 3º na rodada" or "Abre em qui, 19h";
     - a "x de y palpites" progress bar.
   - The existing chip strip (`.round-strip`) and `render_round_jump()` stay; chips gain a `bloqueada` state with a lock icon (`round_state()` is extended).
   - **Match cards** reuse the current markup, with three changes:
     - the crest is replaced by `team_badge()`;
     - locked future cards show "Abre em …" in place of the inputs (a read-only tile with the user's pick if one exists);
     - past rounds start with a points summary.
   - A "Jogos remarcados" section when there are any.
2. **Jogos** (new `render_jogos()`): a calendar grouped by day ("Hoje", "Amanhã", "sáb, 02/11"), covering −3 to +14 days for all of the group's leagues.
   - Compact rows: league mark, logos, score or time, live badge.
   - A league filter (GET select with `data-autosubmit`).
   - Each row links to its round in the Rodada tab. It is read-only and needs one query.
3. **Ranking**, with sub-views selected by `?vista=`:
   - **Geral**: the existing table and podium.
   - **Rodada**: a round picker with prev/next arrows (replaces the select, which stays as the no-JS fallback), plus a "Destaque da rodada" card for the top scorer.
   - **Rodada a rodada**: a member × last-8-rounds points matrix with sticky name column and horizontal scroll. It comes from one query: `SELECT p.user_id, m.league_id, m.round, SUM(p.points) … GROUP BY …`, using the existing `group_league_ids` join, in a new `group_points_by_round()` in app.php.
4. **Participantes**: unchanged, plus per-member exact-score and hit counts taken from the ranking rows.
5. **Regras** (members) / **Configurar** (owner):
   - The league picker becomes grouped (below).
   - A new "Rodadas" block with radios for `round_mode` (Rodada atual / Atual e próxima / Livre) and `deadline_mode` (Até cada jogo / Até o 1º jogo da rodada).
   - A new `action=rounds` branch in group_settings.php. It needs no recalculation, because points don't change.

**Grouped league picker**, as a new `render_league_picker(array $selected, string $idPrefix)` in group_views.php, shared by create_group.php and render_configurar():
- One `<fieldset>` per country, sorted by country order then league order.
- The legend shows the flag SVG, the country name and an "N selecionadas" counter.
- Brasil, América do Sul and Europa are open; other featured countries use `<details>`, opened automatically when they contain a selection. Non-featured countries sit inside a "Mais países" `<details>`.
- Each option is a check card (`check_option()` gains an optional `$extraHtml`) showing the league logo (24 px, lazy-loaded), the name, a "Liga"/"Copa" tag, the season label and a "fora de temporada" tag. That tag appears when the league has no game in the next 60 days and no game in the last 30, derived from `MAX`/`MIN` of `kickoff_at` per league.
- A "Buscar campeonato" input filters cards in JS (progressive; it is hidden without JS).
- Server validation in create_group.php and group_settings.php: the league must be in the catalog and `is_active`, with **at most 6 leagues per group**. The cap keeps the UI usable and bounds sync cost.
- After saving, any newly selected league that has never been synced gets `synced_full_at = 0`, plus a synchronous fetch of its default scoreboard (1 request, about 0.3 s), so games appear right away. The notice reads "Buscando a tabela completa; pode levar alguns minutos".

**Header:** group_header() lists leagues as flag + short name chips instead of the comma-separated text.

### Team logos and the fallback crest
- **Load logos directly from ESPN's CDN, resized, rather than caching them locally.** Reasons:
  - shared hosting has inode and disk limits and no guaranteed cron;
  - downloading images inside the after-response sync adds latency and failure points, and writing files means permission trouble under `public_html`;
  - ESPN's CDN is fast and already serves the `combiner` resize (64 px ≈ 6.5 KB versus 60 KB);
  - the risk (ESPN blocks hotlinking or changes URLs) is covered by the fallback crest.
- **Optional later:** a `logos/` cache filled lazily by the admin "Sincronizar" button.
- **Saving teams:**
  - `espn_parse_event()` also returns home and away `team` blocks with id, displayName, shortDisplayName, abbreviation, logo, color and alternateColor;
  - a new `sync_upsert_teams()` runs `INSERT … ON DUPLICATE KEY UPDATE`, keeps an espn_id → id map per run, and fills `home_team_id` / `away_team_id` in both insert and update;
  - existing matches get their team ids on the next full sync, so no SQL backfill is needed;
  - league logos are saved into `leagues.logo_url` from `leagues[0].logos[0].href`.
- **`team_badge(array $m, string $size = '')`** in layout.php replaces the `team_crest($name)` calls at group_views 292/349 and admin 418/636. The SQL joins `teams` twice as `ht.*` / `at.*`. It renders:
  ```html
  <span class="badge-team" style="--c1:#144992;--c2:#ffffff">
    [crest SVG fallback]
    <img src="https://a.espncdn.com/combiner/i?img=/i/teamlogos/soccer/500/363.png&w=64&h=64" width="40" height="40" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">
  </span>
  ```
  - The image sits absolutely over the crest; if it fails to load, it removes itself and the crest shows. This works before app.js loads, and there is no Content-Security-Policy to block the inline handler.
- **`team_crest()` changes:** it gains `?string $c1, ?string $c2, ?string $abbr`. With ESPN colors, it uses `#c1` for the field and `#c2` for the pattern (half-and-half, sash or stripes, chosen by hash as today). It falls back to the current hash colors when colors are missing, both are near-white or near-black, or they barely differ. The text color is picked by luminance (white or `#1a1b3a`), and the abbreviation comes from ESPN, falling back to `team_initials()`.
- **Light theme:** dark-on-transparent logos are hard to see on dark surfaces, so the badge gets a subtle light circle in the dark theme (`.badge-team{background:color-mix(...)}` with a plain-color fallback).

---

## 6. Rename, palette and icons (requests 6–8)

**Rename to "Conferência Bolão"**
- The name lives in `SITE_NAME` in `includes/config.php`, which is gitignored and exists on the server.
- **Manual step:** edit it in production through the hPanel File Manager to `define('SITE_NAME', 'Conferência Bolão');`.
- Code changes:
  - `install.php` lines 22 and 39 get the new default.
  - `assets/manifest.webmanifest`: `"name": "Conferência Bolão"`, `"short_name": "Conf. Bolão"` (12 characters or fewer), new `background_color` / `theme_color` `#0f1226`.
  - `layout.php`: theme-color metas (232–233, 246) become `#f4f4fb` / `#0f1226`. The wordmark is two-tone ("Conferência" in ink, "Bolão" in accent) via `<span class="brand-name">` markup at line 265 and the `auth_pitch` wordmark at line 175.
  - The `auth_pitch` tagline keeps the "rodada a rodada" voice.
  - The footer (line 310) reads "Conferência Bolão · bolão entre amigos…".
  - The password-reset email subject and body already use `SITE_NAME` (app.php:642–649).
  - Update the README title and the comment at the top of style.css.
  - **Leave the ~40 generic uses of the word "bolão" in UI copy ("Novo bolão", "Sair do bolão") unchanged**; they mean "the pool", not the brand.
- **Verify:** `grep -rn "Bolão'" install.php assets/manifest.webmanifest README.md` should find nothing; then view page source for `<title>`.

**Palette**
- Calm deep indigo / navy with lavender, no neon. **It replaces the token values in style.css lines 16–157, keeping the same names.**
- `--warning*` is new. Write the dark block once as a shared list, applied to both `@media (prefers-color-scheme: dark) :root:not([data-theme=light])` and `:root[data-theme=dark]`.

Light theme:
```
--bg:#f4f4fb; --bg-glow:rgba(122,108,214,.10); --surface:#ffffff; --surface-2:#f8f8fd; --surface-3:#ececf7; --sunken:#efeff8;
--line:#e2e2f0; --line-strong:#c9c9e0; --field-border:#7a7ca3;
--ink:#1a1b3a; --ink-soft:#474a72; --ink-faint:#5d6088;
--accent:#4b3fa8; --accent-hover:#3f348f; --on-accent:#ffffff; --accent-text:#4a3fa0; --accent-soft:rgba(75,63,168,.10); --accent-line:rgba(75,63,168,.35);
--success:#2a7f5c; --success-text:#1f6b4c; --success-soft:rgba(42,127,92,.10); --success-line:rgba(42,127,92,.35);
--warning:#b9822a; --warning-text:#7a4f08; --warning-soft:rgba(185,130,42,.12); --warning-line:rgba(185,130,42,.40);
--danger:#b8403f; --danger-hover:#a03535; --on-danger:#ffffff; --danger-text:#a23a3d; --danger-soft:rgba(184,64,63,.08); --danger-line:rgba(184,64,63,.35);
--focus:#5b4fc9; --topbar-bg:rgba(255,255,255,.86); --tabbar-bg:rgba(255,255,255,.94);
--shadow-1:0 1px 2px rgba(26,27,58,.06),0 1px 1px rgba(26,27,58,.04); --shadow-2:0 12px 32px -14px rgba(26,27,58,.20),0 2px 6px rgba(26,27,58,.05); --shadow-pop:0 18px 40px -16px rgba(26,27,58,.30);
--board:#1b1e3d; --board-2:#242849; --board-line:#343a66; --board-ink:#e6e0ff; --board-soft:#a3a8d4;
```

Dark theme:
```
--bg:#0f1226; --bg-glow:rgba(169,156,240,.08); --surface:#171a33; --surface-2:#1d2140; --surface-3:#252a4f; --sunken:#0b0d1c;
--line:#2a2f55; --line-strong:#3b4170; --field-border:#7379ae;
--ink:#eceefb; --ink-soft:#bcc0e0; --ink-faint:#9297c2;
--accent:#a99cf0; --accent-hover:#bbb0f5; --on-accent:#16133a; --accent-text:#c3b9ff; --accent-soft:rgba(169,156,240,.12); --accent-line:rgba(169,156,240,.40);
--success:#5fcf9f; --success-text:#9fe5c4; --success-soft:rgba(95,207,159,.12); --success-line:rgba(95,207,159,.38);
--warning:#e2b866; --warning-text:#f0d59f; --warning-soft:rgba(226,184,102,.12); --warning-line:rgba(226,184,102,.40);
--danger:#f08a8a; --danger-hover:#f4a1a1; --on-danger:#2a0d12; --danger-text:#f6b3b3; --danger-soft:rgba(240,138,138,.11); --danger-line:rgba(240,138,138,.40);
--focus:#b9adff; --topbar-bg:rgba(15,18,38,.82); --tabbar-bg:rgba(15,18,38,.94);
--shadow-1:0 1px 0 rgba(255,255,255,.03) inset,0 1px 2px rgba(0,0,0,.35); --shadow-2:0 1px 0 rgba(255,255,255,.04) inset,0 14px 34px -16px rgba(0,0,0,.7); --shadow-pop:0 20px 44px -18px rgba(0,0,0,.85);
--board:#0b0d1e; --board-2:#161a36; --board-line:#262b52;
```

**WCAG contrast (computed):**

| Pair | Light | Dark |
|---|---|---|
| ink on bg / surface / surface-3 | 15.2 / 16.6 / 14.2 | 16.0 / 14.8 / 12.0 |
| ink-soft | 7.7 on bg | 10.4 on bg |
| ink-faint | 5.5 on bg, 5.1 on surface-3 | 6.6 on bg, 4.9 on surface-3 |
| accent-text | 7.6 | 10.3 |
| success-text | 5.9 | 12.8 |
| warning-text | 6.5 | 13.0 |
| danger-text | 6.0 | 10.6 |
| button text on accent | white on `#4b3fa8` 8.2 (hover 10.0) | `#16133a` on `#a99cf0` 7.3 |
| button text on danger | white 5.5 | 7.5 |
| button text on success | white 4.9 | 8.0 |
| field-border (3:1 needed) | 3.7 on bg, 3.4 on surface-3 | 3.3 on surface-3, 4.1 on surface |
| focus ring | 5.6 | 9.2 |

- Score tiles: digits `#e6e0ff` on `#141834` = 13.6 and on `#0b0d1e` = 15.1; light board 12.7.
- Login panel: `#e8e6fb` on `#1b1b45` = 13.3; lead text `#c4c3e8` = 9.6.
- The warning color `#b9822a` is only for dots and borders (3.3:1, enough for non-text).
- Everything passes AA, and almost everything passes AAA for body text.

**Hard-coded colors to replace with tokens** (style.css):
- 478–479: `.btn.primary` yellow shadow becomes `rgba(75,63,168,.35)` / `var(--accent-line)`.
- 807–811: the login-panel gradient becomes `linear-gradient(160deg,#272a5c,#0f1226 70%)` with `rgba(169,156,240,.18)` glow.
- 841, 850–852, 883: login yellow becomes `#c3b9ff`.
- 1367, 1413: tile bg `#050a14` becomes `var(--board)`.
- 1379–1384, 1398: yellow focus on tiles becomes `var(--focus)` / `rgba(185,173,255,.45)`.
- 1382, 1420: placeholder becomes `#4a4f80`.
- 1392, 1397: step buttons become `#dcdcf5` / `#2e3463`.
- 1551: progress gradient becomes `var(--accent),#c3b9ff`.
- 1555: unchanged except the end stop becomes `var(--success-text)`.
- 1609: the podium crown becomes `var(--warning)`.
- `.chip.is-pending` / `.badge.is-missing` / `.is-soon` move from accent to the `--warning` tokens.
- The "Ao vivo" state uses `--danger` softened. No pulsing neon; keep the existing dot animation but slow it to 2 s.

**Icons**
- `assets/favicon.svg` becomes an original "CB" monogram: `<svg viewBox="0 0 32 32"><rect width="32" height="32" rx="9" fill="#2b2766"/><path d="…" />` with a thin lavender (`#a99cf0`) arc in the lower right, a nod to a ball's curve, and the letters "CB" in `#f1eeff`. Contrast is 11.6:1. Use paths, not `<text>`, so it renders without fonts.
- `brand_mark()` in layout.php is redrawn from the same SVG, with the yellow ball removed.
- Regenerate `icon-192.png`, `icon-512.png`, `icon-maskable-512.png` (with a safe-zone variant whose background fills to the edges) and `apple-touch-icon.png` (180 px). No build tools are needed:
  - on macOS, `qlmanage -t -s 512 -o <scratch> favicon.svg`, or `rsvg-convert -w 512` if librsvg is installed;
  - or open the SVG in a browser and export.
- `asset()` cache-busts by modification time, so new icons and the manifest load automatically. Installed home-screen apps may keep the old icon until reinstalled; say so in the README.

---

## 7. Sync cost design (shared hosting)

- **Only leagues in use are synced:**
  ```sql
  SELECT l.* FROM leagues l
  WHERE l.external_id IS NOT NULL
    AND EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id)
  ```
  The admin's "Sincronizar agora" can also force any league.
- **Scheduling is per league**, using `leagues.synced_quick_at`, `synced_full_at`, `has_live` and `sync_failures`. `sync_due()` stays as the cheap global gate, every 60 s.
  - **Quick (every 5 min, or 1 min when that league has a live game):**
    - only leagues that have a non-void game between now − 3 h and now + 36 h in the database, or pending results (`sync_pending_days`), or have never synced;
    - requests: the default scoreboard plus pending days only.
  - **Full (every 6 h per league):** at most **2 leagues per run**, oldest `synced_full_at` first, so the work is staggered.
    - Window: kickoff from now − 7 d to now + 35 d. For leagues, use the days from `calendar` inside that window; for cups, use the days inside stages that overlap the window.
    - That is at most about 45 requests per league.
  - **Backfill (once per league):** 30 calendar days per run until the season start has been covered.
- **Hard budget of about 60 ESPN requests per run.** Lower `CURLOPT_TIMEOUT` from 25 to 12 s. curl_multi runs 15 in parallel, so a typical run takes 2–5 s after the response is sent. Without `litespeed_finish_request` (not detached), only the quick sync runs, as today.
- **Backoff:** if a league's default scoreboard returns null, increment `sync_failures`; skip that league for min(2^failures, 60) minutes; reset on success.
- `last_full` / `last_quick` in `sync_state` stay for the admin panel, which also gets a per-league table of last sync, games and failures, and generic help text in place of line 348.

---

## 8. Ordered tasks (each small and checkable on its own)

Setup: `brew install php mariadb` → `mysql -e "create database bolao_dev"` → import `sql/schema.sql` → `php -S 127.0.0.1:8080`.

1. **Migration and schema.**
   - Files: `sql/migrations/002_ligas_times_rodadas.sql`, `sql/schema.sql`, `install.php` ($migrationTables), README migration table.
   - Verify: import 002 twice with no errors; `SHOW COLUMNS FROM leagues/matches/bolao_groups`; `SHOW TABLES` lists `teams`. Fresh database: import schema.sql, then 002, with no errors.
2. **Self-migration and `ensure_schema()`.**
   - Files: `includes/sync.php` (`SYNC_SCHEMA = '3'`, `sync_migrate()`), `includes/app.php`.
   - Verify: on a database with only 001, open `/index.php`; the columns appear and `sync_state.schema = 3`. `php -l`.
3. **League catalog.**
   - Files: new `includes/leagues.php` (COUNTRIES, LEAGUE_CATALOG from §2, `country_flag_svg`, `leagues_seed`); remove `SYNC_LEAGUES`; `sync_leagues()` reads the database.
   - Verify: `SELECT code,country,kind,sort_order FROM leagues ORDER BY country,sort_order` returns about 55 rows; the three existing ids are unchanged.
4. **Saving teams and logos in the sync.**
   - Files: sync.php `espn_parse_event`, `sync_save_events`, new `sync_upsert_teams`, league logo and season label.
   - Verify: admin "Sincronizar agora"; `SELECT COUNT(*) FROM teams` > 0; `SELECT COUNT(*) FROM matches WHERE external_id IS NOT NULL AND home_team_id IS NULL` returns about 0.
5. **Per-league scheduling, active leagues only, budget and backoff.**
   - Files: sync.php `sync_run`, `sync_due`, `espn_fetch_many` timeout.
   - Verify: add `error_log` of request counts; with one group on eng.1 and bra.1, a full run makes ≤ 60 requests and only those leagues get `synced_*` updated; run `time php -r 'require "includes/app.php"; var_dump(sync_run("full"));'`.
6. **Round numbering.**
   - Files: sync.php — TheSportsDB generalized per league; backfill; `sync_number_cup_rounds`; `CUP_STAGES` extended.
   - Verify: Premier League rounds match TheSportsDB (`SELECT round, COUNT(*) … GROUP BY round` gives 10 per round); UCL gets labels "Fase de liga · Rodada N"; Copa do Brasil semifinal gets ida/volta.
7. **`includes/rounds.php` (pure functions).**
   - Functions: `league_rounds`, `current_round`, `open_rounds`, `can_predict`, `round_opens_at`, `round_context`.
   - Verify: a scratch CLI script with fixtures covering the cases below asserts the expected open sets.
     - a normal round;
     - a game postponed by 3 weeks;
     - a void game;
     - a round spread over 5 days;
     - overlapping midweek rounds;
     - a stuck game with no score;
     - end of season;
     - each of the three modes.
8. **predict.php enforcement.**
   - Verify: with curl, a session cookie and a CSRF token, POST a pick for a future-round match; the flash shows "a rodada ainda não abriu" and no row is inserted. A pick in the current round saves.
9. **Group settings: modes and grouped picker.**
   - Files: group_views `render_configurar` / `render_regras`, new `render_league_picker`, group_settings.php `action=rounds` plus the 6-league cap, create_group.php, layout `check_option`.
   - Verify: create a group with 7 leagues and get an error; with 2, a newly activated league is fetched immediately.
10. **Team badge and crest.**
    - Files: layout.php `team_badge`, `team_crest` colors; SQL joins in group.php and admin; CSS `.badge-team`.
    - Verify: logos load lazily (Network tab shows combiner requests about 6 KB); block `a.espncdn.com` in devtools and colored crests appear.
11. **Rodada tab.**
    - Files: group.php context (round ctx, banner data, rescheduled games); group_views `render_round_banner`, locked-card state, `round_state` with `bloqueada`; app.js countdown for the banner; tab key alias.
    - Verify: visually, the past, current and future round states; `?tab=palpites` still works.
12. **Jogos tab** (`render_jogos`, one query).
    - Verify: correct day groups and league filter.
13. **Ranking sub-views** (`group_points_by_round`, matrix, round arrows, "Destaque da rodada").
    - Verify: totals in the matrix match the Geral totals.
14. **index.php pending counts** respect gating (`group_predictable`).
    - Verify: a group in `rodada` mode no longer counts next month's games.
15. **Rename.** install.php defaults, manifest, layout wordmark and footer, README, style.css header; a note to edit the production config.php.
    - Verify: grep, and check the password-reset email subject locally (log `mail()` arguments).
16. **Palette.** Token blocks, hard-coded colors, `--warning`.
    - Verify: both themes in light and dark OS settings and via the toggle; check contrast with devtools "Inspect, contrast".
17. **Icons.** favicon.svg, `brand_mark()`, PNGs, manifest colors.
    - Verify: `/assets/manifest.webmanifest` loads; Lighthouse PWA icon check.
18. **README and admin text.** League list, modes, migration 002, sync behavior.

Run `php -l` on every touched PHP file after each task. Deploy order: import 002 in phpMyAdmin, upload files, edit `SITE_NAME` in config.php, then press "Sincronizar agora".

---

## 9. Risks and how they're handled

- **ESPN rate limits and timeouts:**
  - per-run budget, 12 s timeout, per-league backoff, only active leagues, 2 full syncs per run;
  - ESPN's API is unofficial, so its shape can change; keep the `?? []` defensive parsing and record `last_error` per league.
- **No range queries:** the full sync necessarily costs one request per day, so the window (−7/+35 d) plus a one-time backfill is the main lever.
- **TheSportsDB limits** on the shared test key `123`: keep the global 10-minute and 3-request throttle. With several leagues, filling all official rounds takes hours; the date estimate covers the gap.
- **After-response work on LiteSpeed:** if `litespeed_finish_request` isn't available, only the quick sync runs on page views and full syncs depend on the admin button, exactly as today. Mention it in the admin panel.
- **Existing data:**
  - picks link to `match_id`, so renumbering cup and league rounds never orphans them;
  - the round-ranking keys (`league-round`) for old cup rounds change, but they are computed at request time;
  - `round_mode` defaults to `rodada` for existing groups, which is the owner's stated intent. Picks already made for future rounds are kept and shown locked. Owners can switch to `livre` in Configurar.
- **Manually entered matches** (admin, no `external_id`, no teams): they still work. They have no team id, so they get the hash crest, and round numbers come from the admin. Gating applies the same way.
- **Schema drift:** if the code is deployed before 002 is imported, `ensure_schema()` adds the columns at runtime. If the database user lacks ALTER permission (unusual on Hostinger), the page fails, so the README must say to import 002 first.
- **Cache busting:** `asset()` already adds `?v=filemtime` to CSS, JS, icons and the manifest. External ESPN logos cache on their CDN. Installed apps keep old icons until reinstalled.
- **Logo hotlinking:** if ESPN blocks it or changes URLs, `onerror` falls back to the colored crest. `referrerpolicy="no-referrer"` reduces the chance of referer-based blocking.
- **Seasons ending:** many leagues are off-season (state championships, bra.3, fra.coupe_de_france). The "fora de temporada" tag and the `is_active` admin switch stop people creating groups with nothing to play.
- **Performance:** group.php loads every match of one league (about 380 rows); index.php's 90-day window covers at most 6 leagues per group. The new `idx_league_kick` index covers both.

### Critical files for implementation
- /Users/rafaelgomes/Documents/Projeto/bolao-github/includes/sync.php
- /Users/rafaelgomes/Documents/Projeto/bolao-github/includes/group_views.php
- /Users/rafaelgomes/Documents/Projeto/bolao-github/group.php
- /Users/rafaelgomes/Documents/Projeto/bolao-github/predict.php
- /Users/rafaelgomes/Documents/Projeto/bolao-github/includes/app.php
- Also: /Users/rafaelgomes/Documents/Projeto/bolao-github/includes/layout.php, /Users/rafaelgomes/Documents/Projeto/bolao-github/assets/style.css, /Users/rafaelgomes/Documents/Projeto/bolao-github/sql/schema.sql, /Users/rafaelgomes/Documents/Projeto/bolao-github/create_group.php, /Users/rafaelgomes/Documents/Projeto/bolao-github/group_settings.php, /Users/rafaelgomes/Documents/Projeto/bolao-github/index.php, /Users/rafaelgomes/Documents/Projeto/bolao-github/assets/manifest.webmanifest, /Users/rafaelgomes/Documents/Projeto/bolao-github/assets/favicon.svg, /Users/rafaelgomes/Documents/Projeto/bolao-github/install.php
- New: includes/leagues.php, includes/rounds.php, sql/migrations/002_ligas_times_rodadas.sql