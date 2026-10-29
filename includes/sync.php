<?php
// Sincronização automática de jogos e placares com a API pública da ESPN (gratuita, sem chave).
// Roda sozinha depois que a página é entregue ao visitante (no máximo uma vez por minuto) e cuida
// só das ligas que estão em algum bolão, cada uma no seu ritmo (ver sync_plan). O admin pode forçar
// pelo botão "Sincronizar agora".

const ESPN_BASE = 'https://site.api.espn.com/apis/site/v2/sports/soccer/';

const SYNC_TICK = 60;                 // intervalo mínimo entre duas sincronizações automáticas
const SYNC_FULL_EVERY = 6 * 3600;     // tabela de cada liga (jogos novos e remarcados)
const SYNC_IDLE_EVERY = 5 * 60;       // jogos do momento de cada liga
const SYNC_LIVE_EVERY = 60;           // ... quando a liga tem jogo ao vivo
const SYNC_FULL_PER_RUN = 2;          // ligas com tabela completa por execução (o trabalho fica espalhado)
const SYNC_BUDGET = 60;               // consultas à ESPN por execução automática
const SYNC_ADMIN_BUDGET = 250;        // consultas no botão "Sincronizar agora"
const SYNC_WINDOW_BEFORE = 7 * 86400; // a tabela completa vai de 7 dias atrás...
const SYNC_WINDOW_AFTER = 35 * 86400; // ... até 35 dias à frente
const SYNC_BACKFILL_DAYS = 20;        // dias buscados por vez até cobrir o começo da temporada
const SYNC_SCHEMA = '3';

// A ESPN não informa a rodada das ligas; o TheSportsDB (chave pública de teste) informa, para as ligas
// com 'tsdb' no catálogo (includes/leagues.php). Ele bloqueia quem faz muitas consultas seguidas, então
// cada sincronização busca poucas rodadas (no máximo uma leva a cada 10 minutos, somando todas as ligas),
// e cada rodada só é buscada até vir completa (o confronto mandante × visitante não muda de rodada).
const TSDB_ROUND_URL = 'https://www.thesportsdb.com/api/v1/json/123/eventsround.php?id=%d&s=%s&r=%d';
const TSDB_PER_RUN = 3;

/** Times com nomes diferentes nas duas fontes (já sem acento e espaço, ver team_key). */
const TEAM_ALIASES = [
    'athleticoparanaense' => 'athleticopr',
    'atleticomineiro'     => 'atleticomg',
    'atleticogoianiense'  => 'atleticogo',
    'americamineiro'      => 'americamg',
    'redbullbragantino'   => 'bragantino',
];

/** Nomes das fases das copas, como a ESPN escreve no calendário. Outros "3rd Round" viram "3ª fase". */
const CUP_STAGES = [
    'First Round'             => '1ª fase',
    'Second Round'            => '2ª fase',
    'Third Round'             => '3ª fase',
    'Fourth Round'            => '4ª fase',
    'Fifth Round'             => '5ª fase',
    'First Stage'             => '1ª fase',
    'Second Stage'            => '2ª fase',
    'Third Stage'             => '3ª fase',
    'Preliminary Round'       => 'Fase preliminar',
    'Qualifying'              => 'Fase preliminar',
    'Group'                   => 'Fase de grupos',
    'Group Stage'             => 'Fase de grupos',
    'League Phase'            => 'Fase de liga',
    'League Stage'            => 'Fase de liga',
    'Knockout Round Playoffs' => 'Playoffs',
    'Playoff Round'           => 'Playoffs',
    'Play-off Round'          => 'Playoffs',
    'Rd of 32'                => '16 avos de final',
    'Round of 32'             => '16 avos de final',
    'Rd of 16'                => 'Oitavas de final',
    'Round of 16'             => 'Oitavas de final',
    'Quarterfinals'           => 'Quartas de final',
    'Semifinals'              => 'Semifinal',
    'Final'                   => 'Final',
    'Finals'                  => 'Final',
];

/**
 * Agenda a sincronização para depois que a resposta já foi enviada, assim ninguém espera por ela.
 * Sem como liberar a resposta antes, só faz a parte rápida (poucas consultas).
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
            if (sync_due()) {
                sync_run($detached ? 'auto' : 'quick');
            }
        } catch (Throwable $e) {
            error_log('bolao sync: ' . $e->getMessage());
        }
    });
}

/** Já passou um minuto desde a última sincronização? O que cada liga precisa é decidido em sync_plan(). */
function sync_due(): bool
{
    return time() - (int) (sync_state()['last_tick'] ?? 0) >= SYNC_TICK;
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

/**
 * Ligas a sincronizar: as do catálogo (com slug da ESPN) que estão em algum bolão.
 * Devolve código => linha de leagues (id, slug, cup e o controle de sincronização).
 */
function sync_leagues(): array
{
    $rows = db()->query(
        'SELECT l.* FROM leagues l
         WHERE l.external_id IS NOT NULL AND l.external_id <> \'\'
           AND EXISTS (SELECT 1 FROM group_leagues gl WHERE gl.league_id = l.id)
         ORDER BY l.id'
    )->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['slug'] = $row['external_id'];
        $row['cup'] = ($row['kind'] ?? 'liga') === 'copa';
        $out[$row['code']] = $row;
    }
    return $out;
}

/**
 * Busca e grava jogos das ligas em uso.
 * 'auto': cada liga no seu ritmo (rápida, completa e o começo da temporada), até SYNC_BUDGET consultas;
 * 'quick': só a parte rápida; 'full' (botão do admin): tabela completa de todas as ligas em uso.
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
        $state = ['last_tick' => $now, 'last_quick' => $now];
        if ($mode === 'full') {
            $state['last_full'] = $now;
        }
        sync_set_state($state);

        $leagues = sync_leagues();
        $plan = sync_plan($leagues, $mode, $now);
        $budget = $mode === 'full' ? SYNC_ADMIN_BUDGET : SYNC_BUDGET;
        $stats = ['mode' => $mode, 'leagues' => count($plan), 'requests' => 0, 'events' => 0, 'new' => 0,
            'results' => 0, 'failed_requests' => 0];
        if (!$plan) {
            return $stats;
        }

        // 1) Página padrão de cada liga: traz o calendário (dias ou fases), a temporada e os jogos do momento.
        $urls = [];
        foreach ($plan as $code => $p) {
            $urls[$code] = ESPN_BASE . $leagues[$code]['slug'] . '/scoreboard';
        }
        $boards = espn_fetch_many($urls);
        $requests = count($urls);

        // 2) Dias a buscar, por prioridade: jogos sem placar, a janela da tabela completa, o começo da temporada.
        $pending = sync_pending_days($now);
        $dayUrls = [];
        $add = function (string $code, string $day) use (&$dayUrls, &$requests, $budget, $leagues): bool {
            $key = $code . '|' . $day;
            if (isset($dayUrls[$key])) {
                return true;
            }
            if ($requests >= $budget) {
                return false;
            }
            $dayUrls[$key] = ESPN_BASE . $leagues[$code]['slug'] . '/scoreboard?dates=' . $day;
            $requests++;
            return true;
        };
        foreach ($plan as $code => $p) {
            if ($boards[$code] !== null) {
                foreach (array_unique($pending[$leagues[$code]['id']] ?? []) as $day) {
                    $add($code, $day);
                }
            }
        }
        foreach ($plan as $code => $p) {
            if ($p['full'] && $boards[$code] !== null) {
                foreach (sync_window_days($leagues[$code], $boards[$code], $now) as $day) {
                    $add($code, $day);
                }
            }
        }
        $backfill = []; // código => [dias buscados, dia até onde fica coberto]
        foreach ($plan as $code => $p) {
            if (!$p['backfill'] || $boards[$code] === null) {
                continue;
            }
            [$days, $doneDay] = sync_backfill_days($leagues[$code], $boards[$code], $now);
            $taken = [];
            foreach (array_slice($days, 0, SYNC_BACKFILL_DAYS) as $day) {
                if (!$add($code, $day)) {
                    break;
                }
                $taken[] = $day;
            }
            // Coberto até o último dia buscado; se buscou tudo o que faltava, até o início da janela.
            $covered = count($taken) === count($days) ? $doneDay : end($taken);
            if ($covered) {
                $backfill[$code] = [$taken, $covered];
            }
        }
        $dayBoards = $dayUrls ? espn_fetch_many($dayUrls) : [];
        $stats['requests'] = $requests;

        // 3) Junta os jogos de cada liga, sem repetir.
        $events = [];
        $failedDays = [];
        foreach ($boards as $code => $board) {
            foreach ($board['events'] ?? [] as $event) {
                $events[$code][$event['id']] = $event;
            }
        }
        foreach ($dayBoards as $key => $board) {
            [$code, $day] = explode('|', $key);
            if ($board === null) {
                $stats['failed_requests']++;
                $failedDays[$code][$day] = true;
                continue;
            }
            foreach ($board['events'] ?? [] as $event) {
                $events[$code][$event['id']] = $event;
            }
        }

        // 4) Grava, numera as rodadas e anota o controle de cada liga.
        $mark = $pdo->prepare(
            'UPDATE leagues SET synced_quick_at = ?, synced_full_at = ?, sync_failures = ?, backfill_day = ? WHERE id = ?'
        );
        foreach ($plan as $code => $p) {
            $league = $leagues[$code];
            $board = $boards[$code];
            if ($board === null) {
                $stats['failed_requests']++;
                $mark->execute([$now, $league['synced_full_at'], min(20, (int) $league['sync_failures'] + 1), $league['backfill_day'], $league['id']]);
                continue;
            }
            sync_league_info($league['id'], $board);
            $stages = $league['cup'] ? ($board['leagues'][0]['calendar'][0]['entries'] ?? []) : [];
            $saved = sync_save_events($league['id'], array_values($events[$code] ?? []), $stages);
            $stats['events'] += $saved['events'];
            $stats['new'] += $saved['new'];
            $stats['results'] += $saved['results'];
            sync_number_league($league, $board);

            $backfillDay = $league['backfill_day'];
            if (isset($backfill[$code]) && !array_intersect_key($failedDays[$code] ?? [], array_flip($backfill[$code][0]))) {
                $backfillDay = $backfill[$code][1];
            }
            $mark->execute([$now, $p['full'] ? $now : $league['synced_full_at'], 0, $backfillDay, $league['id']]);
        }

        sync_mark_live($now);
        sync_set_state(['last_error' => '']);
        return $stats;
    } catch (Throwable $e) {
        sync_set_state(['last_error' => date('d/m H:i') . ' ' . mb_substr($e->getMessage(), 0, 200)]);
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bolao_sync')");
    }
}

/**
 * O que fazer em cada liga nesta execução: código => ['full' => bool, 'backfill' => bool].
 * Rápida (página padrão e dias sem placar): a cada 5 min (1 min com jogo ao vivo), só para ligas com jogo
 * entre 3 h atrás e 36 h à frente, com placar atrasado, ou nunca sincronizadas.
 * Completa: a cada 6 h por liga, no máximo SYNC_FULL_PER_RUN por vez, as mais antigas primeiro.
 * Começo da temporada: uma liga por vez, até cobrir tudo. Liga que falhou espera 2, 4, 8... até 60 min.
 */
function sync_plan(array $leagues, string $mode, int $now): array
{
    if (!$leagues) {
        return [];
    }
    if ($mode === 'full') {
        return array_map(fn () => ['full' => true, 'backfill' => true], $leagues);
    }

    $stmt = db()->prepare(
        'SELECT DISTINCT league_id FROM matches m
         WHERE ' . not_void_sql('m') . ' AND (
             m.kickoff_at BETWEEN ? AND ?
             OR (m.home_score IS NULL AND m.external_id IS NOT NULL AND m.kickoff_at BETWEEN ? AND ?)
         )'
    );
    $stmt->execute([
        date('Y-m-d H:i:s', $now - 3 * 3600), date('Y-m-d H:i:s', $now + 36 * 3600),
        date('Y-m-d H:i:s', $now - 10 * 86400), date('Y-m-d H:i:s', $now),
    ]);
    $active = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $plan = [];
    $fullQueue = [];
    $backfillQueue = [];
    $backfillBefore = espn_day($now - SYNC_WINDOW_BEFORE - 2 * 86400);
    foreach ($leagues as $code => $l) {
        $failures = (int) $l['sync_failures'];
        if ($failures > 0 && $now - (int) $l['synced_quick_at'] < min(2 ** $failures, 60) * 60) {
            continue; // esperando depois de falhar
        }
        $every = (int) $l['has_live'] ? SYNC_LIVE_EVERY : SYNC_IDLE_EVERY;
        $neverSynced = (int) $l['synced_quick_at'] === 0;
        if ($now - (int) $l['synced_quick_at'] >= $every && ($neverSynced || isset($active[$l['id']]))) {
            $plan[$code] = ['full' => false, 'backfill' => false];
        }
        if ($mode === 'auto' && $now - (int) $l['synced_full_at'] >= SYNC_FULL_EVERY) {
            $fullQueue[$code] = (int) $l['synced_full_at'];
        }
        if ($mode === 'auto' && ($l['backfill_day'] === null || $l['backfill_day'] < $backfillBefore)) {
            $backfillQueue[$code] = (string) $l['backfill_day'];
        }
    }
    asort($fullQueue);
    foreach (array_slice(array_keys($fullQueue), 0, SYNC_FULL_PER_RUN) as $code) {
        $plan[$code] = ['full' => true, 'backfill' => false];
    }
    asort($backfillQueue);
    foreach (array_slice(array_keys($backfillQueue), 0, 1) as $code) {
        $plan[$code] = ['full' => $plan[$code]['full'] ?? false, 'backfill' => true];
    }
    return $plan;
}

/**
 * Busca agora a página padrão de uma liga recém-escolhida num bolão (uma consulta), para os jogos
 * aparecerem logo. O resto da tabela vem nas próximas sincronizações (synced_full_at = 0).
 */
function sync_league_now(int $leagueId): bool
{
    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('bolao_sync', 0)")->fetchColumn() !== 1) {
        return false; // a sincronização em andamento (ou a próxima) cuida dela
    }
    try {
        sync_migrate();
        $stmt = $pdo->prepare('SELECT * FROM leagues WHERE id = ? AND external_id IS NOT NULL AND external_id <> \'\'');
        $stmt->execute([$leagueId]);
        $league = $stmt->fetch();
        if (!$league) {
            return false;
        }
        $league['id'] = (int) $league['id'];
        $league['slug'] = $league['external_id'];
        $league['cup'] = $league['kind'] === 'copa';
        $board = espn_fetch_many(['b' => ESPN_BASE . $league['slug'] . '/scoreboard'])['b'];
        if ($board === null) {
            return false;
        }
        sync_league_info($league['id'], $board);
        $stages = $league['cup'] ? ($board['leagues'][0]['calendar'][0]['entries'] ?? []) : [];
        sync_save_events($league['id'], $board['events'] ?? [], $stages);
        sync_number_league($league, $board);
        return true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bolao_sync')");
    }
}

/**
 * Ligas recém-escolhidas num bolão que nunca foram sincronizadas: busca já a página padrão de cada uma
 * (os jogos do momento aparecem na hora). Devolve true se alguma ainda vai receber a tabela completa.
 */
function fetch_new_leagues(array $leagueIds): bool
{
    $leagueIds = array_values(array_map('intval', $leagueIds));
    if (!$leagueIds) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT id FROM leagues WHERE id IN (' . placeholders($leagueIds) . ')
           AND synced_quick_at = 0 AND external_id IS NOT NULL AND external_id <> \'\''
    );
    $stmt->execute($leagueIds);
    $fresh = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    @set_time_limit(60);
    foreach ($fresh as $id) {
        try {
            sync_league_now($id);
        } catch (Throwable $e) {
            error_log('bolao sync liga ' . $id . ': ' . $e->getMessage());
        }
    }
    return (bool) $fresh;
}

/** Início e fim da temporada atual da liga, pela página padrão da ESPN. [início, fim] em timestamp. */
function sync_season_window(array $board): array
{
    $season = $board['leagues'][0]['season'] ?? [];
    $start = isset($season['startDate']) ? strtotime($season['startDate']) : false;
    $end = isset($season['endDate']) ? strtotime($season['endDate']) : false;
    $year = (int) ($season['year'] ?? date('Y'));
    return [$start ?: mktime(0, 0, 0, 1, 1, $year), $end ?: mktime(0, 0, 0, 1, 1, $year + 1)];
}

/**
 * Dias da tabela completa: de 7 dias atrás a 35 à frente. Nas ligas, só os dias do calendário com jogos;
 * nas copas, todos os dias dentro das fases que cruzam a janela.
 */
function sync_window_days(array $league, array $board, int $now): array
{
    return sync_days_between($league, $board, $now - SYNC_WINDOW_BEFORE, $now + SYNC_WINDOW_AFTER);
}

/**
 * Dias que faltam do começo da temporada até o início da janela da tabela completa (depois de
 * leagues.backfill_day). Nas copas, a partir do começo da fase em andamento 7 dias atrás.
 * Devolve [dias em ordem, dia até onde fica coberto quando todos forem buscados].
 */
function sync_backfill_days(array $league, array $board, int $now): array
{
    [$seasonStart] = sync_season_window($board);
    $until = $now - SYNC_WINDOW_BEFORE;
    $from = $seasonStart;
    if ($league['cup']) {
        foreach ($board['leagues'][0]['calendar'][0]['entries'] ?? [] as $stage) {
            $s = strtotime($stage['startDate'] ?? '');
            $e = strtotime($stage['endDate'] ?? '');
            if ($s && $e && $s <= $until && $until < $e) {
                $from = max($from, $s);
            }
        }
    }
    $days = sync_days_between($league, $board, $from, $until);
    $after = (string) ($league['backfill_day'] ?? '');
    $days = array_values(array_filter($days, fn ($d) => $d > $after));
    return [$days, espn_day($until)];
}

/** Dias (formato da ESPN) com jogos possíveis entre dois horários, em ordem. */
function sync_days_between(array $league, array $board, int $from, int $to): array
{
    $days = [];
    if ($league['cup']) {
        $stages = $board['leagues'][0]['calendar'][0]['entries'] ?? [];
        for ($t = $from; $t <= $to; $t += 86400) {
            foreach ($stages as $stage) {
                if ($t >= strtotime($stage['startDate'] ?? '') && $t < strtotime($stage['endDate'] ?? '')) {
                    $days[] = espn_day($t);
                    break;
                }
            }
        }
        // Copa sem calendário de fases: todos os dias.
        if (!$stages) {
            for ($t = $from; $t <= $to; $t += 86400) {
                $days[] = espn_day($t);
            }
        }
    } else {
        foreach ($board['leagues'][0]['calendar'] ?? [] as $day) {
            $t = is_string($day) ? strtotime($day) : false;
            if ($t && $t >= $from - 86400 && $t <= $to) {
                $days[] = espn_day($t + 12 * 3600); // o calendário marca o começo do dia em UTC
            }
        }
    }
    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

/** Guarda o escudo da liga, o nome e o início da temporada atual, como vêm na página padrão da ESPN. */
function sync_league_info(int $leagueId, array $board): void
{
    $league = $board['leagues'][0] ?? [];
    $logo = (string) ($league['logos'][0]['href'] ?? '');
    $logo = (strpos($logo, 'https://') === 0 && strlen($logo) <= 255) ? $logo : null;
    // "2026-27 English Premier League" vira "2026-27"; "2026 Copa do Brasil" vira "2026".
    $label = preg_match('/^(\d{4}(?:[-\/]\d{2,4})?)/', (string) ($league['season']['displayName'] ?? ''), $m) ? $m[1] : null;
    $start = isset($league['season']['startDate']) ? strtotime($league['season']['startDate']) : false;
    db()->prepare(
        'UPDATE leagues SET logo_url = COALESCE(?, logo_url), season_label = COALESCE(?, season_label),
            season_start = COALESCE(?, season_start) WHERE id = ?'
    )->execute([$logo, $label, $start ? date('Y-m-d', $start) : null, $leagueId]);
}

/** Dias (no formato da ESPN) de jogos que já deviam ter começado e ainda não têm placar, por liga. */
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

/** Marca em cada liga se há jogo ao vivo (começou há menos de 2h30 e ainda não terminou). */
function sync_mark_live(int $now): void
{
    $stmt = db()->prepare(
        "SELECT DISTINCT league_id FROM matches
         WHERE external_id IS NOT NULL AND home_score IS NULL AND (status IS NULL OR status <> 'void')
           AND kickoff_at BETWEEN ? AND ?"
    );
    $stmt->execute([date('Y-m-d H:i:s', $now - 150 * 60), date('Y-m-d H:i:s', $now + 5 * 60)]);
    $live = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    db()->exec('UPDATE leagues SET has_live = 0 WHERE has_live = 1');
    if ($live) {
        db()->prepare('UPDATE leagues SET has_live = 1 WHERE id IN (' . placeholders($live) . ')')->execute($live);
    }
    sync_set_state(['live' => $live ? 1 : 0]);
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
             home_score, away_score, live_home_score, live_away_score, status, status_detail, home_team_id, away_team_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE matches SET note = ?, home_team = ?, away_team = ?,
            kickoff_at = ?, home_score = ?, away_score = ?, live_home_score = ?, live_away_score = ?,
            status = ?, status_detail = ?, home_team_id = COALESCE(?, home_team_id), away_team_id = COALESCE(?, away_team_id)
         WHERE id = ?'
    );

    // Primeiro os times de todos os jogos (uma gravação por time), depois os jogos.
    $parsed = [];
    $teams = [];
    foreach ($events as $event) {
        $m = espn_parse_event($event, $stages);
        if ($m === null) {
            continue;
        }
        foreach (['home', 'away'] as $side) {
            if ($m[$side] !== null) {
                $teams[$m[$side]['espn_id']] = $m[$side];
            }
        }
        $parsed[] = $m;
    }
    $teamIds = sync_upsert_teams($teams);

    $stats = ['events' => 0, 'new' => 0, 'results' => 0];
    foreach ($parsed as $m) {
        $stats['events']++;
        $homeId = $m['home'] ? ($teamIds[$m['home']['espn_id']] ?? null) : null;
        $awayId = $m['away'] ? ($teamIds[$m['away']['espn_id']] ?? null) : null;

        $old = $existing[$m['external_id']] ?? null;
        if ($old === null) {
            $insert->execute([
                $leagueId, $m['external_id'], $m['round'] ?? 1, $m['round_label'], $m['note'],
                $m['home_team'], $m['away_team'], $m['kickoff_at'], $m['home_score'], $m['away_score'],
                $m['live_home_score'], $m['live_away_score'], $m['status'], $m['status_detail'], $homeId, $awayId,
            ]);
            $stats['new']++;
            continue;
        }

        // A rodada e o nome da fase não mudam aqui: quem cuida é a numeração (sync_number_league).
        $update->execute([
            $m['note'], $m['home_team'], $m['away_team'], $m['kickoff_at'],
            $m['home_score'], $m['away_score'], $m['live_home_score'], $m['live_away_score'],
            $m['status'], $m['status_detail'], $homeId, $awayId, $old['id'],
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
            $round = (int) $stage['value'] * 20 + 1; // provisório: sync_number_cup_rounds() numera de vez
            $roundLabel = cup_stage_label((string) $stage['label']);
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
        'home'            => espn_team($home['team'] ?? []),
        'away'            => espn_team($away['team'] ?? []),
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

/** Dados do time na ESPN (id, nomes, sigla, escudo e cores). Null sem id. */
function espn_team(array $team): ?array
{
    $id = (string) ($team['id'] ?? '');
    if ($id === '' || !preg_match('/^[0-9]{1,20}$/', $id)) {
        return null;
    }
    $color = function ($value): ?string {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    };
    $logo = (string) ($team['logo'] ?? '');
    return [
        'espn_id'      => $id,
        'name'         => mb_substr((string) ($team['displayName'] ?? ''), 0, 100),
        'short_name'   => isset($team['shortDisplayName']) ? mb_substr((string) $team['shortDisplayName'], 0, 60) : null,
        'abbreviation' => isset($team['abbreviation']) ? mb_substr((string) $team['abbreviation'], 0, 10) : null,
        'logo_url'     => (strpos($logo, 'https://') === 0 && strlen($logo) <= 255) ? $logo : null,
        'color'        => $color($team['color'] ?? null),
        'alt_color'    => $color($team['alternateColor'] ?? null),
    ];
}

/** Grava (ou atualiza) os times e devolve id da ESPN => teams.id. */
function sync_upsert_teams(array $teams): array
{
    if (!$teams) {
        return [];
    }
    $upsert = db()->prepare(
        'INSERT INTO teams (espn_id, name, short_name, abbreviation, logo_url, color, alt_color)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name),
             abbreviation = VALUES(abbreviation), logo_url = COALESCE(VALUES(logo_url), logo_url),
             color = COALESCE(VALUES(color), color), alt_color = COALESCE(VALUES(alt_color), alt_color)'
    );
    foreach ($teams as $t) {
        $upsert->execute([$t['espn_id'], $t['name'], $t['short_name'], $t['abbreviation'], $t['logo_url'], $t['color'], $t['alt_color']]);
    }
    $ids = array_keys($teams);
    $stmt = db()->prepare('SELECT id, espn_id FROM teams WHERE espn_id IN (' . placeholders($ids) . ')');
    $stmt->execute(array_map('strval', $ids));
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['espn_id']] = (int) $row['id'];
    }
    return $map;
}

/** Numera as rodadas de uma liga depois de gravar os jogos: copas por fase, ligas pela rodada oficial ou estimada. */
function sync_number_league(array $league, array $board): void
{
    [$start, $end] = sync_season_window($board);
    if ($league['cup']) {
        sync_number_cup_rounds($league['id'], $board['leagues'][0]['calendar'][0]['entries'] ?? [], $start, $end);
        return;
    }
    $season = (int) ($board['leagues'][0]['season']['year'] ?? date('Y', $start));
    $tsdb = league_meta((string) $league['code'])['tsdb'] ?? null;
    if ($tsdb) {
        sync_official_rounds($league['id'], $season, $tsdb);
    }
    sync_number_rounds($league['id'], $start, $end, $tsdb ? $season : null);
}

/**
 * Rodadas das copas: fase × 20 + sequência dentro da fase.
 * - Fase com ida e volta (nota da ESPN): 1 = ida, 2 = volta ("Oitavas de final · ida").
 * - Fase de grupos ou de liga: estimada pelas datas (sync_guess_rounds), "Fase de liga · Rodada 3".
 * - Fase de jogo único: sequência 1 e o nome da fase.
 * Os palpites apontam para o jogo (match_id), então renumerar não perde nada.
 */
function sync_number_cup_rounds(int $leagueId, array $stages, int $start, int $end): void
{
    if (!$stages) {
        return;
    }
    $stmt = db()->prepare(
        'SELECT id, round, round_label, kickoff_at, home_team, away_team, note, status FROM matches
         WHERE league_id = ? AND external_id IS NOT NULL AND kickoff_at >= ? AND kickoff_at < ?
         ORDER BY kickoff_at, id'
    );
    $stmt->execute([$leagueId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)]);

    $byStage = [];
    foreach ($stmt->fetchAll() as $m) {
        $t = strtotime($m['kickoff_at']);
        foreach ($stages as $stage) {
            if ($t >= strtotime($stage['startDate'] ?? '') && $t < strtotime($stage['endDate'] ?? '')) {
                $byStage[(int) $stage['value']]['label'] = cup_stage_label((string) ($stage['label'] ?? ''));
                $byStage[(int) $stage['value']]['matches'][] = $m;
                break;
            }
        }
    }

    $update = db()->prepare('UPDATE matches SET round = ?, round_label = ? WHERE id = ?');
    foreach ($byStage as $value => $group) {
        $numbered = cup_stage_rounds($group['matches'], $group['label']);
        foreach ($group['matches'] as $m) {
            [$seq, $label] = $numbered[(int) $m['id']];
            $round = $value * 20 + min(19, $seq);
            if ((int) $m['round'] !== $round || (string) $m['round_label'] !== $label) {
                $update->execute([$round, $label, $m['id']]);
            }
        }
    }
}

/** Sequência e nome de cada jogo de uma fase de copa: id => [sequência, nome]. Ver sync_number_cup_rounds(). */
function cup_stage_rounds(array $matches, string $label): array
{
    $out = [];
    $legs = array_filter($matches, fn ($m) => in_array($m['note'], ['Jogo de ida', 'Jogo de volta'], true));
    if ($legs) {
        // Jogo sem nota numa fase de ida e volta: fica com a perna de data mais próxima.
        $legTimes = [1 => [], 2 => []];
        foreach ($legs as $m) {
            $legTimes[$m['note'] === 'Jogo de volta' ? 2 : 1][] = strtotime($m['kickoff_at']);
        }
        foreach ($matches as $m) {
            if ($m['note'] === 'Jogo de ida' || $m['note'] === 'Jogo de volta') {
                $seq = $m['note'] === 'Jogo de volta' ? 2 : 1;
            } else {
                $t = strtotime($m['kickoff_at']);
                $dist = fn (array $times) => $times ? min(array_map(fn ($x) => abs($x - $t), $times)) : PHP_INT_MAX;
                $seq = $dist($legTimes[2]) < $dist($legTimes[1]) ? 2 : 1;
            }
            $out[(int) $m['id']] = [$seq, $label . ' · ' . ($seq === 2 ? 'volta' : 'ida')];
        }
        return $out;
    }

    $played = array_values(array_filter($matches, fn ($m) => ($m['status'] ?? null) !== 'void'));
    $guess = count($played) >= 2 ? sync_guess_rounds($played) : [];
    $many = $guess && max($guess) > 1;
    foreach ($matches as $m) {
        $seq = $guess[(int) $m['id']] ?? null;
        if ($seq === null) {
            // Adiado ou cancelado: a rodada do jogo disputado mais próximo em data.
            $seq = 1;
            $best = PHP_INT_MAX;
            foreach ($played as $p) {
                $d = abs(strtotime($p['kickoff_at']) - strtotime($m['kickoff_at']));
                if ($d < $best && isset($guess[(int) $p['id']])) {
                    $best = $d;
                    $seq = $guess[(int) $p['id']];
                }
            }
        }
        $out[(int) $m['id']] = [$seq, $many ? $label . ' · Rodada ' . $seq : $label];
    }
    return $out;
}

/** Nome da fase em português ("Rd of 16" vira "Oitavas de final", "3rd Round" vira "3ª fase"). */
function cup_stage_label(string $label): string
{
    if (isset(CUP_STAGES[$label])) {
        return CUP_STAGES[$label];
    }
    if (preg_match('/^(\d+)(st|nd|rd|th) (Round|Stage)$/i', $label, $m)) {
        return (int) $m[1] . 'ª fase';
    }
    return mb_substr($label, 0, 40);
}

/**
 * Busca no TheSportsDB as rodadas oficiais que ainda não estão completas no banco.
 * $tsdb: [id da liga no TheSportsDB, formato da temporada, nº de times] (ver LEAGUE_CATALOG).
 * No máximo uma leva a cada 10 minutos somando todas as ligas; a leva continua de onde a anterior
 * parou, para uma rodada que nunca vem completa não travar as outras.
 */
function sync_official_rounds(int $leagueId, int $season, array $tsdb): void
{
    $state = sync_state();
    if (time() - (int) ($state['tsdb_at'] ?? 0) < 600) {
        return;
    }
    [$tsdbId, $format, $teams] = $tsdb;
    $rounds = $teams * 2 - 2;
    $games = intdiv($teams, 2);

    $stmt = db()->prepare('SELECT round, COUNT(*) AS games FROM official_rounds WHERE league_id = ? AND season = ? GROUP BY round');
    $stmt->execute([$leagueId, $season]);
    $have = array_column($stmt->fetchAll(), 'games', 'round');

    $missing = [];
    for ($round = 1; $round <= $rounds; $round++) {
        if ((int) ($have[$round] ?? 0) < $games) {
            $missing[] = $round;
        }
    }
    if (!$missing) {
        return;
    }
    // Continua depois da última rodada buscada desta liga (cursor "liga:rodada").
    $cursor = explode(':', (string) ($state['tsdb_cursor'] ?? ''));
    $after = ((int) ($cursor[0] ?? 0) === $leagueId) ? (int) ($cursor[1] ?? 0) : 0;
    $next = array_values(array_filter($missing, fn ($r) => $r > $after));
    $pick = array_slice(array_merge($next, $missing), 0, TSDB_PER_RUN);
    $pick = array_values(array_unique($pick));

    $seasonText = $format === 'Y-Y' ? $season . '-' . ($season + 1) : (string) $season;
    $urls = [];
    foreach ($pick as $round) {
        $urls[$round] = sprintf(TSDB_ROUND_URL, $tsdbId, $seasonText, $round);
    }
    sync_set_state(['tsdb_at' => time(), 'tsdb_cursor' => $leagueId . ':' . end($pick)]);
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
 * Numera as rodadas de uma liga (jogos da temporada atual, entre $start e $end).
 * Com rodada oficial ($season do TheSportsDB): cada confronto recebe a sua; os demais ficam com a
 * rodada estimada pela tabela (sync_guess_rounds), alinhada às oficiais já conhecidas. Sem nenhuma
 * rodada oficial, vale só a estimativa.
 */
function sync_number_rounds(int $leagueId, int $start, int $end, ?int $season): void
{
    // Jogos adiados também recebem a rodada oficial, mas ficam fora das estimativas por data.
    $stmt = db()->prepare(
        'SELECT id, round, kickoff_at, home_team, away_team, status FROM matches
         WHERE league_id = ? AND external_id IS NOT NULL AND kickoff_at >= ? AND kickoff_at < ?
         ORDER BY kickoff_at, id'
    );
    $stmt->execute([$leagueId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)]);
    $all = $stmt->fetchAll();
    if (!$all) {
        return;
    }
    $matches = array_values(array_filter($all, fn ($m) => ($m['status'] ?? null) !== 'void'));

    $official = [];
    if ($season !== null) {
        $stmt = db()->prepare('SELECT home_key, away_key, round FROM official_rounds WHERE league_id = ? AND season = ?');
        $stmt->execute([$leagueId, $season]);
        foreach ($stmt->fetchAll() as $row) {
            $official[$row['home_key'] . '|' . $row['away_key']] = (int) $row['round'];
        }
    }
    $keyOf = official_key_matcher(array_keys($official));

    $rounds = [];
    $known = []; // [horário, rodada] dos jogos disputados com rodada oficial
    foreach ($all as $m) {
        $key = $keyOf($m['home_team']) . '|' . $keyOf($m['away_team']);
        if (isset($official[$key])) {
            $rounds[(int) $m['id']] = $official[$key];
            if (($m['status'] ?? null) !== 'void') {
                $known[] = [strtotime($m['kickoff_at']), $official[$key]];
            }
        }
    }

    // Sem rodada oficial: vale a estimativa pela tabela (sync_guess_rounds), alinhada com as rodadas
    // oficiais já conhecidas. Cada grupo estimado com algum jogo oficial vira a rodada oficial mais comum
    // nele; os outros seguem a diferença (oficial − estimada) do grupo com dados mais próximo.
    // Jogos remarcados (a mais de 4 dias da mediana da sua rodada oficial) não votam.
    $guess = sync_guess_rounds($matches);
    $timesOf = [];
    foreach ($matches as $m) {
        if (isset($rounds[(int) $m['id']])) {
            $timesOf[$rounds[(int) $m['id']]][] = strtotime($m['kickoff_at']);
        }
    }
    $median = [];
    foreach ($timesOf as $r => $times) {
        sort($times);
        $median[$r] = $times[intdiv(count($times), 2)];
    }
    $votes = [];
    foreach ($matches as $m) {
        $id = (int) $m['id'];
        if (isset($rounds[$id], $guess[$id]) && abs(strtotime($m['kickoff_at']) - $median[$rounds[$id]]) <= 4 * 86400) {
            $votes[$guess[$id]][$rounds[$id]] = ($votes[$guess[$id]][$rounds[$id]] ?? 0) + 1;
        }
    }
    $groupRound = [];
    foreach ($votes as $g => $counts) {
        arsort($counts);
        $groupRound[$g] = (int) array_key_first($counts);
    }
    foreach ($all as $m) {
        $id = (int) $m['id'];
        if (isset($rounds[$id])) {
            continue;
        }
        if (!isset($guess[$id])) {
            // Adiado ou cancelado sem rodada oficial: a do jogo conhecido mais próximo em data.
            $time = strtotime($m['kickoff_at']);
            $best = null;
            foreach ($known as [$knownTime, $knownRound]) {
                if ($best === null || abs($knownTime - $time) < abs($best[0] - $time)) {
                    $best = [$knownTime, $knownRound];
                }
            }
            if ($best !== null) {
                $rounds[$id] = $best[1];
            }
            continue;
        }
        $g = $guess[$id];
        if (isset($groupRound[$g])) {
            $rounds[$id] = $groupRound[$g];
            continue;
        }
        $offset = 0;
        $nearest = null;
        foreach ($groupRound as $og => $or) {
            if ($nearest === null || abs($og - $g) < abs($nearest - $g)) {
                $nearest = $og;
                $offset = $or - $og;
            }
        }
        $rounds[$id] = max(1, $g + $offset);
    }

    $stored = array_column($all, 'round', 'id');
    $update = db()->prepare('UPDATE matches SET round = ? WHERE id = ?');
    foreach ($rounds as $id => $round) {
        if (isset($stored[$id]) && (int) $stored[$id] !== $round) {
            $update->execute([$round, $id]);
        }
    }
}

/**
 * Função que converte o nome do time da ESPN na chave usada pelo TheSportsDB. Primeiro team_key();
 * se essa chave não existe lá, procura uma única chave parecida, sem "and", "fc" e "afc"
 * ("AFC Bournemouth" = "Bournemouth", "Brighton & Hove Albion" = "Brighton and Hove Albion").
 */
function official_key_matcher(array $pairKeys): callable
{
    $teams = [];
    foreach ($pairKeys as $pair) {
        foreach (explode('|', $pair) as $k) {
            $teams[$k] = true;
        }
    }
    $loose = fn (string $k) => str_replace(['afc', 'fc', 'and'], '', $k);
    $looseTeams = [];
    foreach (array_keys($teams) as $k) {
        $looseTeams[$k] = $loose($k);
    }
    $cache = [];
    return function (string $name) use ($teams, $looseTeams, $loose, &$cache): string {
        $key = team_key($name);
        if (isset($teams[$key]) || !$teams) {
            return $key;
        }
        if (!isset($cache[$key])) {
            $lk = $loose($key);
            $found = [];
            foreach ($looseTeams as $official => $lo) {
                if ($lo === $lk || (strlen($lo) >= 4 && strlen($lk) >= 4 && (strpos($lo, $lk) !== false || strpos($lk, $lo) !== false))) {
                    $found[] = $official;
                }
            }
            $cache[$key] = count($found) === 1 ? $found[0] : $key;
        }
        return $cache[$key];
    };
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
                CURLOPT_TIMEOUT        => 12,
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
