<?php
// Sincronização automática de jogos e placares com a API pública da ESPN (gratuita, sem chave).
// Roda sozinha depois que a página é entregue ao visitante (a cada 5 min, ou 1 min com jogo ao vivo),
// e o admin pode forçar pelo botão "Sincronizar agora".

const ESPN_BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer/';

/** Ligas sincronizadas. A chave é o código gravado na tabela leagues (BRA-A já existia no site). */
const SYNC_LEAGUES = [
    'BRA-A'        => ['slug' => 'bra.1', 'name' => 'Brasileirão Série A', 'cup' => false],
    'BRA-CDB'      => ['slug' => 'bra.copa_do_brazil', 'name' => 'Copa do Brasil', 'cup' => true],
    'CONMEBOL-LIB' => ['slug' => 'conmebol.libertadores', 'name' => 'Libertadores', 'cup' => true],
];

const SYNC_FULL_EVERY = 6 * 3600;  // tabela completa, para pegar jogos novos e remarcados
const SYNC_IDLE_EVERY = 5 * 60;
const SYNC_LIVE_EVERY = 60;
const SYNC_SCHEMA = '3';

// A ESPN não informa a rodada do Brasileirão; o TheSportsDB (chave pública de teste) informa.
// Ele bloqueia quem faz muitas consultas seguidas, então cada sincronização busca poucas rodadas,
// e cada rodada só é buscada até vir completa (o confronto mandante × visitante não muda de rodada).
const TSDB_ROUND_URL = 'https://www.thesportsdb.com/api/v1/json/123/eventsround.php?id=4351&s=%d&r=%d';
const TSDB_ROUNDS = 38;
const TSDB_GAMES_PER_ROUND = 10;
const TSDB_PER_RUN = 3;

/** Times com nomes diferentes nas duas fontes (já sem acento e espaço, ver team_key). */
const TEAM_ALIASES = [
    'athleticoparanaense' => 'athleticopr',
    'atleticomineiro'     => 'atleticomg',
    'atleticogoianiense'  => 'atleticogo',
    'americamineiro'      => 'americamg',
    'redbullbragantino'   => 'bragantino',
];

/** Nomes das fases das copas, como a ESPN escreve no calendário. */
const CUP_STAGES = [
    'First Round'   => '1ª fase',
    'Second Round'  => '2ª fase',
    'Third Round'   => '3ª fase',
    'Fourth Round'  => '4ª fase',
    'Fifth Round'   => '5ª fase',
    'First Stage'   => '1ª fase',
    'Second Stage'  => '2ª fase',
    'Third Stage'   => '3ª fase',
    'Group'         => 'Fase de grupos',
    'Group Stage'   => 'Fase de grupos',
    'Rd of 32'      => '16 avos de final',
    'Rd of 16'      => 'Oitavas de final',
    'Quarterfinals' => 'Quartas de final',
    'Semifinals'    => 'Semifinal',
    'Final'         => 'Final',
];

/**
 * Agenda a sincronização para depois que a resposta já foi enviada, assim ninguém espera por ela.
 * Sem como liberar a resposta antes, só faz a sincronização rápida (poucas requisições).
 */
function sync_schedule(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    register_shutdown_function(function (): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // libera a sessão para o próximo clique do visitante
        }

        $detached = false;
        if (function_exists('litespeed_finish_request')) {
            $detached = litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            $detached = fastcgi_finish_request();
        }

        ignore_user_abort(true);
        @set_time_limit(120);

        try {
            $mode = sync_due();
            if ($mode === 'full' && !$detached) {
                $mode = 'quick';
            }
            if ($mode !== null) {
                sync_run($mode);
            }
        } catch (Throwable $e) {
            error_log('bolao sync: ' . $e->getMessage());
        }
    });
}

/** 'full', 'quick' ou null, conforme o tempo desde a última sincronização. */
function sync_due(): ?string
{
    $state = sync_state();
    $now = time();

    if ($now - (int) ($state['last_full'] ?? 0) >= SYNC_FULL_EVERY) {
        return 'full';
    }
    $every = !empty($state['live']) ? SYNC_LIVE_EVERY : SYNC_IDLE_EVERY;
    if ($now - (int) ($state['last_quick'] ?? 0) >= $every) {
        return 'quick';
    }
    return null;
}

function sync_state(): array
{
    try {
        $rows = db()->query('SELECT name, value FROM sync_state')->fetchAll();
    } catch (Throwable $e) {
        return []; // tabela ainda não existe: a primeira sincronização cria
    }
    return array_column($rows, 'value', 'name');
}

function sync_set_state(array $values): void
{
    $stmt = db()->prepare(
        'INSERT INTO sync_state (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    foreach ($values as $name => $value) {
        $stmt->execute([$name, (string) $value]);
    }
}

/**
 * Cria as tabelas e colunas que faltam (o mesmo que as migrações 001/002 fazem), uma vez só.
 * Roda antes da página (ensure_schema, em app.php) para o site não quebrar se a migração
 * não foi importada; e de novo na sincronização, por garantia.
 */
function sync_migrate(): void
{
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sync_state (
            name VARCHAR(50) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        ) DEFAULT CHARSET = utf8mb4'
    );

    if ((sync_state()['schema'] ?? '') === SYNC_SCHEMA) {
        return;
    }

    sync_add_columns('matches', [
        'external_id'     => 'ADD COLUMN external_id VARCHAR(50) NULL',
        'round_label'     => 'ADD COLUMN round_label VARCHAR(60) NULL',
        'note'            => 'ADD COLUMN note VARCHAR(60) NULL',
        'status'          => 'ADD COLUMN status VARCHAR(10) NULL',
        'status_detail'   => 'ADD COLUMN status_detail VARCHAR(40) NULL',
        'live_home_score' => 'ADD COLUMN live_home_score TINYINT UNSIGNED NULL',
        'live_away_score' => 'ADD COLUMN live_away_score TINYINT UNSIGNED NULL',
        'home_team_id'    => 'ADD COLUMN home_team_id INT UNSIGNED NULL',
        'away_team_id'    => 'ADD COLUMN away_team_id INT UNSIGNED NULL',
    ]);
    $index = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND INDEX_NAME = 'idx_league_kick'"
    )->fetchColumn();
    if ((int) $index === 0) {
        $pdo->exec('ALTER TABLE matches ADD INDEX idx_league_kick (league_id, kickoff_at)');
    }

    sync_add_columns('leagues', [
        'country'         => "ADD COLUMN country VARCHAR(12) NOT NULL DEFAULT 'OUT'",
        'kind'            => "ADD COLUMN kind ENUM('liga','copa') NOT NULL DEFAULT 'liga'",
        'short_name'      => 'ADD COLUMN short_name VARCHAR(40) NULL',
        'sort_order'      => 'ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999',
        'logo_url'        => 'ADD COLUMN logo_url VARCHAR(255) NULL',
        'season_label'    => 'ADD COLUMN season_label VARCHAR(20) NULL',
        'season_start'    => 'ADD COLUMN season_start DATE NULL',
        'is_active'       => 'ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
        'synced_full_at'  => 'ADD COLUMN synced_full_at INT UNSIGNED NOT NULL DEFAULT 0',
        'synced_quick_at' => 'ADD COLUMN synced_quick_at INT UNSIGNED NOT NULL DEFAULT 0',
        'backfill_day'    => 'ADD COLUMN backfill_day CHAR(8) NULL',
        'sync_failures'   => 'ADD COLUMN sync_failures TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'has_live'        => 'ADD COLUMN has_live TINYINT(1) NOT NULL DEFAULT 0',
    ]);

    sync_add_columns('bolao_groups', [
        'round_mode'    => "ADD COLUMN round_mode ENUM('rodada','duas','livre') NOT NULL DEFAULT 'rodada'",
        'deadline_mode' => "ADD COLUMN deadline_mode ENUM('jogo','rodada') NOT NULL DEFAULT 'jogo'",
    ]);

    // Times da ESPN (escudo, sigla e cores).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS teams (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            espn_id VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            short_name VARCHAR(60) NULL,
            abbreviation VARCHAR(10) NULL,
            logo_url VARCHAR(255) NULL,
            color CHAR(6) NULL,
            alt_color CHAR(6) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET = utf8mb4'
    );

    // Rodada oficial de cada confronto, vinda do TheSportsDB.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS official_rounds (
            league_id INT UNSIGNED NOT NULL,
            season SMALLINT UNSIGNED NOT NULL,
            home_key VARCHAR(60) NOT NULL,
            away_key VARCHAR(60) NOT NULL,
            round SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (league_id, season, home_key, away_key)
        ) DEFAULT CHARSET = utf8mb4'
    );

    sync_set_state(['schema' => SYNC_SCHEMA]);
}

/** Acrescenta numa tabela as colunas que ainda não existem (coluna => trecho "ADD COLUMN ..."). */
function sync_add_columns(string $table, array $wanted): void
{
    $stmt = db()->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $changes = [];
    foreach ($wanted as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $changes[] = $sql;
        }
    }
    if ($changes) {
        // $table e $sql vêm sempre do código, nunca do visitante.
        db()->exec('ALTER TABLE ' . $table . ' ' . implode(', ', $changes));
    }
}

/** Garante que as ligas sincronizadas existem. Devolve código => id. */
function sync_leagues(): array
{
    $find = db()->prepare('SELECT id FROM leagues WHERE code = ?');
    $insert = db()->prepare('INSERT INTO leagues (name, code, external_id) VALUES (?, ?, ?)');
    $ids = [];
    foreach (SYNC_LEAGUES as $code => $league) {
        $find->execute([$code]);
        $id = $find->fetchColumn();
        if ($id === false) {
            $insert->execute([$league['name'], $code, $league['slug']]);
            $id = db()->lastInsertId();
        }
        $ids[$code] = (int) $id;
    }
    return $ids;
}

/**
 * Busca e grava jogos. 'full' percorre a temporada; 'quick' só ontem, hoje, amanhã
 * e os dias de jogos que já começaram e ainda não têm placar.
 */
function sync_run(string $mode): array
{
    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('bolao_sync', 0)")->fetchColumn() !== 1) {
        return ['skipped' => true]; // outra sincronização já está rodando
    }

    try {
        sync_migrate();
        $now = time();
        sync_set_state($mode === 'full'
            ? ['last_full' => $now, 'last_quick' => $now]
            : ['last_quick' => $now]);

        $leagueIds = sync_leagues();
        $stats = ['mode' => $mode, 'events' => 0, 'new' => 0, 'results' => 0, 'failed_requests' => 0];

        // 1) Página padrão de cada liga: traz o calendário (dias ou fases) e os jogos do momento.
        $urls = [];
        foreach (SYNC_LEAGUES as $code => $league) {
            $urls[$code] = ESPN_BASE . $league['slug'] . '/scoreboard';
        }
        $boards = espn_fetch_many($urls);

        // 2) Dias a buscar em cada liga.
        $pending = sync_pending_days($now);
        $dayUrls = [];
        foreach (SYNC_LEAGUES as $code => $league) {
            $days = [espn_day($now - 86400), espn_day($now), espn_day($now + 86400)];
            $days = array_merge($days, $pending[$leagueIds[$code]] ?? []);

            if ($mode === 'full') {
                if ($league['cup']) {
                    for ($t = $now - 10 * 86400; $t <= $now + 60 * 86400; $t += 86400) {
                        $days[] = espn_day($t);
                    }
                } else {
                    foreach ($boards[$code]['leagues'][0]['calendar'] ?? [] as $day) {
                        if (is_string($day)) {
                            $days[] = espn_day(strtotime($day));
                        }
                    }
                }
            }

            foreach (array_unique($days) as $day) {
                $dayUrls[$code . '|' . $day] = ESPN_BASE . $league['slug'] . '/scoreboard?dates=' . $day;
            }
        }
        $dayBoards = espn_fetch_many($dayUrls);

        // 3) Junta os jogos de cada liga, sem repetir.
        $events = [];
        foreach ($boards as $code => $board) {
            foreach ($board['events'] ?? [] as $event) {
                $events[$code][$event['id']] = $event;
            }
        }
        foreach ($dayBoards as $key => $board) {
            if ($board === null) {
                $stats['failed_requests']++;
                continue;
            }
            $code = explode('|', $key)[0];
            foreach ($board['events'] ?? [] as $event) {
                $events[$code][$event['id']] = $event;
            }
        }
        $stats['failed_requests'] += count(array_filter($boards, fn ($b) => $b === null));

        foreach (SYNC_LEAGUES as $code => $league) {
            $stages = $league['cup'] ? ($boards[$code]['leagues'][0]['calendar'][0]['entries'] ?? []) : [];
            $saved = sync_save_events($leagueIds[$code], array_values($events[$code] ?? []), $stages);
            $stats['events'] += $saved['events'];
            $stats['new'] += $saved['new'];
            $stats['results'] += $saved['results'];
            if (!$league['cup']) {
                $season = (int) ($boards[$code]['leagues'][0]['season']['year'] ?? date('Y'));
                sync_official_rounds($leagueIds[$code], $season, TSDB_PER_RUN);
                sync_number_rounds($leagueIds[$code], $season);
            }
        }

        sync_set_state(['live' => sync_has_live($now) ? 1 : 0, 'last_error' => '']);
        return $stats;
    } catch (Throwable $e) {
        sync_set_state(['last_error' => date('d/m H:i') . ' ' . mb_substr($e->getMessage(), 0, 200)]);
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bolao_sync')");
    }
}

/** Dias (no formato da ESPN) de jogos que já deviam ter começado e ainda não têm placar. */
function sync_pending_days(int $now): array
{
    $stmt = db()->prepare(
        "SELECT league_id, kickoff_at FROM matches
         WHERE external_id IS NOT NULL AND home_score IS NULL AND (status IS NULL OR status <> 'void')
           AND kickoff_at BETWEEN ? AND ?"
    );
    $stmt->execute([date('Y-m-d H:i:s', $now - 10 * 86400), date('Y-m-d H:i:s', $now)]);

    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $days[(int) $row['league_id']][] = espn_day(strtotime($row['kickoff_at']));
    }
    return $days;
}

/** Algum jogo começou há menos de 2h30 e ainda não terminou? */
function sync_has_live(int $now): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM matches
         WHERE external_id IS NOT NULL AND home_score IS NULL AND (status IS NULL OR status <> 'void')
           AND kickoff_at BETWEEN ? AND ?"
    );
    $stmt->execute([date('Y-m-d H:i:s', $now - 150 * 60), date('Y-m-d H:i:s', $now + 5 * 60)]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Grava os jogos de uma liga e recalcula os pontos dos que acabaram de terminar. */
function sync_save_events(int $leagueId, array $events, array $stages): array
{
    $pdo = db();
    $existing = [];
    $stmt = $pdo->prepare('SELECT id, external_id, home_score, away_score FROM matches WHERE league_id = ? AND external_id IS NOT NULL');
    $stmt->execute([$leagueId]);
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['external_id']] = $row;
    }

    $insert = $pdo->prepare(
        'INSERT INTO matches
            (league_id, external_id, round, round_label, note, home_team, away_team, kickoff_at,
             home_score, away_score, live_home_score, live_away_score, status, status_detail)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE matches SET round = COALESCE(?, round), round_label = ?, note = ?, home_team = ?, away_team = ?,
            kickoff_at = ?, home_score = ?, away_score = ?, live_home_score = ?, live_away_score = ?,
            status = ?, status_detail = ?
         WHERE id = ?'
    );

    $stats = ['events' => 0, 'new' => 0, 'results' => 0];
    foreach ($events as $event) {
        $m = espn_parse_event($event, $stages);
        if ($m === null) {
            continue;
        }
        $stats['events']++;

        $old = $existing[$m['external_id']] ?? null;
        if ($old === null) {
            $insert->execute([
                $leagueId, $m['external_id'], $m['round'] ?? 1, $m['round_label'], $m['note'],
                $m['home_team'], $m['away_team'], $m['kickoff_at'], $m['home_score'], $m['away_score'],
                $m['live_home_score'], $m['live_away_score'], $m['status'], $m['status_detail'],
            ]);
            $stats['new']++;
            continue;
        }

        $update->execute([
            $m['round'], $m['round_label'], $m['note'], $m['home_team'], $m['away_team'], $m['kickoff_at'],
            $m['home_score'], $m['away_score'], $m['live_home_score'], $m['live_away_score'],
            $m['status'], $m['status_detail'], $old['id'],
        ]);

        $changed = $old['home_score'] === null
            ? $m['home_score'] !== null
            : ((int) $old['home_score'] !== $m['home_score'] || (int) $old['away_score'] !== $m['away_score']);
        if ($changed && $m['home_score'] !== null) {
            recalculate_match((int) $old['id']);
            $stats['results']++;
        }
    }
    return $stats;
}

/** Converte um jogo da ESPN para as colunas de matches. Null para jogos ainda sem times definidos. */
function espn_parse_event(array $event, array $stages): ?array
{
    $competition = $event['competitions'][0] ?? [];
    $home = $away = null;
    foreach ($competition['competitors'] ?? [] as $c) {
        if (($c['homeAway'] ?? '') === 'home') {
            $home = $c;
        } elseif (($c['homeAway'] ?? '') === 'away') {
            $away = $c;
        }
    }
    $homeName = $home['team']['displayName'] ?? '';
    $awayName = $away['team']['displayName'] ?? '';
    // strpos (e não str_starts_with) para continuar compatível com PHP 7.4.
    if ($homeName === '' || $awayName === '' || strpos($homeName, 'TBD') === 0 || strpos($awayName, 'TBD') === 0) {
        return null;
    }

    $type = $event['status']['type'] ?? [];
    $state = $type['state'] ?? 'pre';
    // Adiado ou cancelado: vem como encerrado, mas sem ter sido disputado.
    if ($state === 'post' && empty($type['completed'])) {
        $state = 'void';
    }
    $final = $state === 'post';
    $live = $state === 'in';

    $start = strtotime($event['date']);
    $kickoff = (new DateTimeImmutable('@' . $start))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
        ->format('Y-m-d H:i:00');

    $round = null;
    $roundLabel = null;
    foreach ($stages as $stage) {
        if ($start >= strtotime($stage['startDate']) && $start < strtotime($stage['endDate'])) {
            $round = (int) $stage['value'];
            $roundLabel = CUP_STAGES[$stage['label']] ?? $stage['label'];
            break;
        }
    }

    $note = null;
    $headline = $competition['notes'][0]['headline'] ?? '';
    if (strpos($headline, '1st Leg') === 0) {
        $note = 'Jogo de ida';
    } elseif (strpos($headline, '2nd Leg') === 0) {
        $note = 'Jogo de volta';
    }

    return [
        'external_id'     => (string) $event['id'],
        'round'           => $round,
        'round_label'     => $roundLabel,
        'note'            => $note,
        'home_team'       => mb_substr($homeName, 0, 100),
        'away_team'       => mb_substr($awayName, 0, 100),
        'kickoff_at'      => $kickoff,
        'home_score'      => $final ? (int) $home['score'] : null,
        'away_score'      => $final ? (int) $away['score'] : null,
        'live_home_score' => $live ? (int) $home['score'] : null,
        'live_away_score' => $live ? (int) $away['score'] : null,
        'status'          => $state,
        'status_detail'   => isset($type['shortDetail']) ? mb_substr($type['shortDetail'], 0, 40) : null,
    ];
}

/** Busca no TheSportsDB as rodadas oficiais que ainda não estão completas no banco. */
function sync_official_rounds(int $leagueId, int $season, int $limit): void
{
    $stmt = db()->prepare('SELECT round, COUNT(*) AS games FROM official_rounds WHERE league_id = ? AND season = ? GROUP BY round');
    $stmt->execute([$leagueId, $season]);
    $have = array_column($stmt->fetchAll(), 'games', 'round');

    $urls = [];
    for ($round = 1; $round <= TSDB_ROUNDS && count($urls) < $limit; $round++) {
        if ((int) ($have[$round] ?? 0) < TSDB_GAMES_PER_ROUND) {
            $urls[$round] = sprintf(TSDB_ROUND_URL, $season, $round);
        }
    }
    // No máximo uma leva a cada 10 minutos, para não ser bloqueado se alguma rodada nunca vier completa.
    if (!$urls || time() - (int) (sync_state()['tsdb_at'] ?? 0) < 600) {
        return;
    }
    sync_set_state(['tsdb_at' => time()]);
    sync_save_official_rounds($leagueId, $season, espn_fetch_many($urls));
}

/** Grava rodada => resposta do TheSportsDB (lista "events" com intRound, strHomeTeam, strAwayTeam). */
function sync_save_official_rounds(int $leagueId, int $season, array $responses): void
{
    $save = db()->prepare(
        'INSERT INTO official_rounds (league_id, season, home_key, away_key, round) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE round = VALUES(round)'
    );
    foreach ($responses as $round => $data) {
        foreach ($data['events'] ?? [] as $event) {
            if ((int) ($event['intRound'] ?? 0) !== (int) $round) {
                continue;
            }
            $save->execute([$leagueId, $season, team_key($event['strHomeTeam']), team_key($event['strAwayTeam']), $round]);
        }
    }
}

/** Nome do time sem acento, espaço nem pontuação, igual nas duas fontes (ESPN e TheSportsDB). */
function team_key(string $name): string
{
    $key = strtolower(strtr($name, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ç' => 'c',
    ]));
    $key = preg_replace('/[^a-z0-9]/', '', $key);
    return TEAM_ALIASES[$key] ?? $key;
}

/**
 * Numera as rodadas do Brasileirão pela rodada oficial de cada confronto.
 * Jogo sem rodada oficial conhecida fica na rodada do jogo oficial mais próximo em data;
 * sem nenhuma rodada oficial, as rodadas são estimadas pela tabela (sync_guess_rounds).
 */
function sync_number_rounds(int $leagueId, int $season): void
{
    // Jogos adiados também recebem a rodada oficial, mas ficam fora das estimativas por data.
    $stmt = db()->prepare(
        'SELECT id, round, kickoff_at, home_team, away_team, status FROM matches
         WHERE league_id = ? AND external_id IS NOT NULL
         ORDER BY kickoff_at, id'
    );
    $stmt->execute([$leagueId]);
    $all = array_values(array_filter(
        $stmt->fetchAll(),
        fn ($m) => (int) substr($m['kickoff_at'], 0, 4) === $season
    ));
    $matches = array_values(array_filter($all, fn ($m) => ($m['status'] ?? null) !== 'void'));

    $stmt = db()->prepare('SELECT home_key, away_key, round FROM official_rounds WHERE league_id = ? AND season = ?');
    $stmt->execute([$leagueId, $season]);
    $official = [];
    foreach ($stmt->fetchAll() as $row) {
        $official[$row['home_key'] . '|' . $row['away_key']] = (int) $row['round'];
    }

    $rounds = [];
    $known = []; // [horário, rodada] dos jogos disputados com rodada oficial
    foreach ($all as $m) {
        $key = team_key($m['home_team']) . '|' . team_key($m['away_team']);
        if (isset($official[$key])) {
            $rounds[(int) $m['id']] = $official[$key];
            if (($m['status'] ?? null) !== 'void') {
                $known[] = [strtotime($m['kickoff_at']), $official[$key]];
            }
        }
    }

    if (!$known) {
        $rounds = sync_guess_rounds($matches);
    } else {
        foreach ($matches as $m) {
            if (isset($rounds[(int) $m['id']])) {
                continue;
            }
            $time = strtotime($m['kickoff_at']);
            $best = null;
            foreach ($known as [$knownTime, $knownRound]) {
                if ($best === null || abs($knownTime - $time) < abs($best[0] - $time)) {
                    $best = [$knownTime, $knownRound];
                }
            }
            $rounds[(int) $m['id']] = $best[1];
        }
    }

    $stored = array_column($all, 'round', 'id');
    $update = db()->prepare('UPDATE matches SET round = ? WHERE id = ?');
    foreach ($rounds as $id => $round) {
        if ((int) $stored[$id] !== $round) {
            $update->execute([$round, $id]);
        }
    }
}

/**
 * Estimativa das rodadas pela tabela, para quando o TheSportsDB não respondeu ainda:
 * jogos em ordem de data, e uma rodada nova começa quando um time jogaria duas vezes nela ou
 * quando passam mais de 60h sem jogos. Grupos pequenos (jogos remarcados) entram numa rodada vizinha.
 * Devolve id do jogo => rodada.
 */
function sync_guess_rounds(array $matches): array
{
    $teamsOf = [];
    $groups = [];
    $current = null;
    $last = 0;
    foreach ($matches as $m) {
        $id = (int) $m['id'];
        $time = strtotime($m['kickoff_at']);
        $teamsOf[$id] = [$m['home_team'], $m['away_team']];
        $clash = $current !== null
            && (isset($groups[$current]['teams'][$m['home_team']]) || isset($groups[$current]['teams'][$m['away_team']]));
        if ($current === null || $clash || $time - $last > 60 * 3600) {
            $groups[] = ['ids' => [], 'teams' => []];
            $current = count($groups) - 1;
        }
        $groups[$current]['ids'][] = $id;
        $groups[$current]['teams'][$m['home_team']] = true;
        $groups[$current]['teams'][$m['away_team']] = true;
        $last = $time;
    }

    $fits = function (array $group, array $ids) use ($teamsOf): bool {
        foreach ($ids as $id) {
            foreach ($teamsOf[$id] as $team) {
                if (isset($group['teams'][$team])) {
                    return false;
                }
            }
        }
        return true;
    };
    $absorb = function (array &$group, array $ids) use ($teamsOf): void {
        foreach ($ids as $id) {
            $group['ids'][] = $id;
            foreach ($teamsOf[$id] as $team) {
                $group['teams'][$team] = true;
            }
        }
    };

    for ($i = 0; $i < count($groups); $i++) {
        $ids = $groups[$i]['ids'];
        if (count($ids) >= 4) {
            continue;
        }
        $hasNext = isset($groups[$i + 1]);
        $hasPrev = $i > 0;
        if ($hasNext && $fits($groups[$i + 1], $ids)) {
            $absorb($groups[$i + 1], $ids);
        } elseif ($hasPrev && $fits($groups[$i - 1], $ids)) {
            $absorb($groups[$i - 1], $ids);
        } elseif ($hasNext) {
            $absorb($groups[$i + 1], $ids);
        } elseif ($hasPrev) {
            $absorb($groups[$i - 1], $ids);
        } else {
            continue;
        }
        array_splice($groups, $i, 1);
        $i--;
    }

    $rounds = [];
    foreach ($groups as $index => $group) {
        foreach ($group['ids'] as $id) {
            $rounds[$id] = $index + 1;
        }
    }
    return $rounds;
}

/** O parâmetro "dates" da ESPN usa o dia no fuso do Pacífico (EUA). */
function espn_day(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('America/Los_Angeles'))
        ->format('Ymd');
}

/** Baixa várias URLs em paralelo. Devolve chave => JSON decodificado (ou null se falhou). */
function espn_fetch_many(array $urls): array
{
    $results = [];
    $multi = curl_multi_init();

    foreach (array_chunk($urls, 15, true) as $chunk) {
        $handles = [];
        foreach ($chunk as $key => $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Bolao)',
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $key => $handle) {
            $body = curl_multi_getcontent($handle);
            $ok = curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200 && is_string($body);
            $data = $ok ? json_decode($body, true) : null;
            $results[$key] = is_array($data) ? $data : null;
            curl_multi_remove_handle($multi, $handle);
        }
    }

    curl_multi_close($multi);
    return $results;
}
