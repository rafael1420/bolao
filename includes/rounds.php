<?php
// Regras das rodadas: qual é a rodada atual, quais aceitam palpite e por quê.
// Quase tudo aqui é função pura (recebe jogos e o horário, devolve o resultado), para dar para
// testar sem banco: php tests/rounds_test.php. Só round_context() lê o banco.

const ROUND_CORE_WINDOW = 4 * 86400;   // jogo a mais de 4 dias da mediana da rodada é remarcado
const ROUND_STUCK_AFTER = 3 * 86400;   // jogo sem placar há mais de 3 dias não prende a rodada
const ROUND_EARLY_OPEN = 48 * 3600;    // a próxima rodada abre 48 h antes do primeiro jogo dela
const ROUND_LATE_MARGIN = 24 * 3600;   // remarcado abre se for até 24 h depois da última rodada aberta

/**
 * Rodadas de uma liga a partir dos jogos (adiados e cancelados ficam de fora).
 * Cada rodada: number, label, ids (todos os jogos), core (jogos a até 4 dias da mediana),
 * resched (os demais: adiados para semanas depois ou antecipados), start e end (primeiro e último
 * jogo do núcleo), finished (todo jogo do núcleo tem placar ou começou há mais de 3 dias),
 * all_started (todo jogo do núcleo já começou) e last_kick (último jogo da rodada, inclusive remarcados).
 * Devolve ['rounds' => número => rodada, 'by_start' => números em ordem de início,
 * 'numbers' => números em ordem crescente, 'current' => C, 'next' => N (ou null), 'over' => temporada encerrada].
 */
function league_rounds(array $matches, int $now): array
{
    $groups = [];
    foreach ($matches as $m) {
        if (($m['status'] ?? null) === 'void') {
            continue;
        }
        $groups[(int) $m['round']][] = $m;
    }
    ksort($groups);

    $rounds = [];
    foreach ($groups as $number => $list) {
        $times = array_map(fn ($m) => strtotime($m['kickoff_at']), $list);
        $sorted = $times;
        sort($sorted);
        $median = $sorted[intdiv(count($sorted) - 1, 2)];

        $core = $resched = [];
        $start = $end = null;
        $finished = true;
        $allStarted = true;
        $label = null;
        foreach ($list as $i => $m) {
            $t = $times[$i];
            $label = $label ?? (($m['round_label'] ?? null) ?: null);
            if (abs($t - $median) <= ROUND_CORE_WINDOW) {
                $core[] = (int) $m['id'];
                $start = $start === null ? $t : min($start, $t);
                $end = $end === null ? $t : max($end, $t);
                $hasScore = isset($m['home_score']) && $m['home_score'] !== null;
                if (!$hasScore && $t > $now - ROUND_STUCK_AFTER) {
                    $finished = false;
                }
                if ($t > $now && !$hasScore && !in_array($m['status'] ?? null, ['in', 'post'], true)) {
                    $allStarted = false;
                }
            } else {
                $resched[] = (int) $m['id'];
            }
        }
        $rounds[$number] = [
            'number' => $number,
            'label' => $label ?: 'Rodada ' . $number,
            'ids' => array_map(fn ($m) => (int) $m['id'], $list),
            'core' => $core,
            'resched' => $resched,
            'start' => $start,
            'end' => $end,
            'last_kick' => max($times),
            'finished' => $finished,
            'all_started' => $allStarted,
        ];
    }

    $byStart = array_keys($rounds);
    usort($byStart, fn ($a, $b) => [$rounds[$a]['start'], $a] <=> [$rounds[$b]['start'], $b]);

    $current = null;
    foreach ($byStart as $number) {
        if (!$rounds[$number]['finished']) {
            $current = $number;
            break;
        }
    }
    $over = $current === null && $rounds;
    if ($over) {
        $current = max(array_keys($rounds));
    }
    $next = null;
    if ($current !== null && !$over) {
        $pos = array_search($current, $byStart, true);
        $next = $byStart[$pos + 1] ?? null;
    }

    return [
        'rounds' => $rounds,
        'by_start' => $byStart,
        'numbers' => array_keys($rounds),
        'current' => $current,
        'next' => $next,
        'over' => $over,
    ];
}

/** A rodada atual (C) de league_rounds(). Null se a liga não tem jogos. */
function current_round(array $lr): ?int
{
    return $lr['current'];
}

/**
 * Rodadas abertas para palpite, conforme o modo do bolão (bolao_groups.round_mode):
 * - rodada: a atual; a próxima abre quando todo jogo do núcleo da atual começou, ou 48 h antes do
 *   primeiro jogo dela (rodadas seguidas, meio de semana);
 * - duas: a atual e a próxima; a seguinte abre quando a atual não tem mais jogo por começar;
 * - livre: todas (cada jogo fecha no seu início, como era antes).
 * Temporada encerrada: nenhuma.
 */
function open_rounds(array $lr, string $mode, int $now): array
{
    if ($lr['over'] || $lr['current'] === null) {
        return [];
    }
    if ($mode === 'livre') {
        return $lr['numbers'];
    }
    $rounds = $lr['rounds'];
    $c = $lr['current'];
    $n = $lr['next'];
    $open = [$c];
    if ($n === null) {
        return $open;
    }
    if ($mode === 'duas') {
        $open[] = $n;
        $pos = array_search($n, $lr['by_start'], true);
        $after = $lr['by_start'][$pos + 1] ?? null;
        if ($after !== null && $rounds[$c]['all_started']) {
            $open[] = $after;
        }
        return $open;
    }
    if ($rounds[$c]['all_started'] || $rounds[$n]['start'] <= $now + ROUND_EARLY_OPEN) {
        $open[] = $n;
    }
    return $open;
}

/**
 * Quando uma rodada bloqueada abre: [horário ou null, número da rodada que precisa acabar antes ou null].
 * A próxima rodada tem horário (o que vier primeiro: último jogo do núcleo da atual ou 48 h antes do
 * primeiro jogo dela); as seguintes só "abrem depois da rodada X".
 */
function round_opens_at(array $lr, int $number, string $mode): array
{
    $c = $lr['current'];
    $n = $lr['next'];
    if ($c === null || $lr['over'] || $mode === 'livre') {
        return [null, null];
    }
    $rounds = $lr['rounds'];
    $pos = array_search($number, $lr['by_start'], true);
    $before = $pos ? $lr['by_start'][$pos - 1] : null;
    if ($mode === 'rodada' && $number === $n) {
        return [min($rounds[$c]['end'], $rounds[$n]['start'] - ROUND_EARLY_OPEN), $c];
    }
    if ($mode === 'duas' && $n !== null) {
        $npos = array_search($n, $lr['by_start'], true);
        if ($pos === $npos + 1) {
            return [$rounds[$c]['end'], $c];
        }
    }
    return [null, $before];
}

/**
 * Contexto das rodadas de uma liga para um bolão: league_rounds() mais as rodadas abertas, o modo,
 * o prazo e o limite dos jogos remarcados (H: fim da última rodada aberta + 24 h).
 */
function round_ctx_build(array $matches, string $mode, string $deadline, int $now): array
{
    $mode = in_array($mode, ['rodada', 'duas', 'livre'], true) ? $mode : 'rodada';
    $deadline = $deadline === 'rodada' ? 'rodada' : 'jogo';
    $lr = league_rounds($matches, $now);
    $open = open_rounds($lr, $mode, $now);

    $horizon = null;
    foreach ($open as $number) {
        $end = $lr['rounds'][$number]['end'];
        $horizon = $horizon === null ? $end : max($horizon, $end);
    }
    if ($horizon !== null) {
        $horizon += ROUND_LATE_MARGIN;
    }

    // Em que rodada está cada jogo e se é remarcado.
    $where = [];
    foreach ($lr['rounds'] as $number => $r) {
        foreach ($r['resched'] as $id) {
            $where[$id] = [$number, true];
        }
        foreach ($r['core'] as $id) {
            $where[$id] = [$number, false];
        }
    }

    return $lr + [
        'mode' => $mode,
        'deadline' => $deadline,
        'open' => $open,
        'horizon' => $horizon,
        'where' => $where,
        'now' => $now,
    ];
}

/**
 * Pode palpitar neste jogo agora? Devolve ['ok' => bool, 'reason' => null|'finished'|'started'|'void'|
 * 'locked'|'deadline', 'opens_at' => horário ou null, 'after' => rodada que precisa acabar antes ou null].
 * Regras: o jogo não começou (is_open) e
 * - modo livre; ou
 * - a rodada está aberta (com prazo "rodada", o 1º jogo do núcleo ainda não começou); ou
 * - é um jogo remarcado com início até H (abre na semana em que vai ser disputado).
 */
function predict_status(array $m, array $ctx): array
{
    $now = $ctx['now'];
    $out = ['ok' => false, 'reason' => null, 'opens_at' => null, 'after' => null];
    if (($m['status'] ?? null) === 'void') {
        $out['reason'] = 'void';
        return $out;
    }
    if (isset($m['home_score']) && $m['home_score'] !== null) {
        $out['reason'] = 'finished';
        return $out;
    }
    if (in_array($m['status'] ?? null, ['in', 'post'], true) || strtotime($m['kickoff_at']) <= $now) {
        $out['reason'] = 'started';
        return $out;
    }
    if ($ctx['mode'] === 'livre') {
        $out['ok'] = true;
        return $out;
    }

    $id = (int) $m['id'];
    [$number, $resched] = $ctx['where'][$id] ?? [(int) $m['round'], false];
    $round = $ctx['rounds'][$number] ?? null;
    $isOpen = in_array($number, $ctx['open'], true);

    if ($resched) {
        // Remarcado (adiado ou antecipado): vale a semana em que vai ser disputado, não a rodada de origem.
        if (($ctx['horizon'] !== null && strtotime($m['kickoff_at']) <= $ctx['horizon'])) {
            $out['ok'] = true;
            return $out;
        }
        $out['reason'] = 'locked'; // abre quando a rodada da semana dele abrir
        return $out;
    }

    if (!$isOpen) {
        $out['reason'] = 'locked';
        [$out['opens_at'], $out['after']] = round_opens_at($ctx, $number, $ctx['mode']);
        return $out;
    }
    if ($ctx['deadline'] === 'rodada' && $round !== null && $round['start'] !== null && $round['start'] <= $now) {
        $out['reason'] = 'deadline';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}

function can_predict(array $m, array $ctx): bool
{
    return predict_status($m, $ctx)['ok'];
}

/**
 * Estado da rodada na tela: encerrada (resultados), aguardando (tudo começou, faltam placares),
 * aberta (tem jogo aceitando palpite) ou bloqueada (ainda vai abrir).
 */
function round_ui_state(array $ctx, int $number, array $matchesById): string
{
    $r = $ctx['rounds'][$number] ?? null;
    if ($r === null) {
        return 'bloqueada';
    }
    if ($r['finished']) {
        return 'encerrada';
    }
    foreach ($r['ids'] as $id) {
        if (isset($matchesById[$id]) && can_predict($matchesById[$id], $ctx)) {
            return 'aberta';
        }
    }
    if ($r['all_started'] || in_array($number, $ctx['open'], true) || $ctx['mode'] === 'livre') {
        return 'aguardando';
    }
    return 'bloqueada';
}

/**
 * Prazo da rodada para o cabeçalho: com prazo "rodada", o primeiro jogo do núcleo;
 * senão o próximo jogo aberto. Null se nada está aberto.
 */
function round_next_deadline(array $ctx, int $number, array $matchesById): ?int
{
    $r = $ctx['rounds'][$number] ?? null;
    if ($r === null) {
        return null;
    }
    $next = null;
    foreach ($r['ids'] as $id) {
        if (isset($matchesById[$id]) && can_predict($matchesById[$id], $ctx)) {
            $t = strtotime($matchesById[$id]['kickoff_at']);
            $next = $next === null ? $t : min($next, $t);
        }
    }
    if ($next !== null && $ctx['deadline'] === 'rodada' && $r['start'] !== null && $r['start'] > $ctx['now']) {
        return $r['start'];
    }
    return $next;
}

/**
 * Contexto das rodadas de uma liga para um bolão (guardado durante a requisição).
 * Usa os jogos da temporada atual (leagues.season_start), inclusive adiados.
 * Devolve [contexto, jogos da liga por id].
 */
function round_context(int $leagueId, array $group, int $now): array
{
    static $cache = [];
    $key = $leagueId . '|' . ($group['round_mode'] ?? '') . '|' . ($group['deadline_mode'] ?? '') . '|' . $now;
    if (!isset($cache[$key])) {
        $matches = league_season_matches($leagueId);
        $cache[$key] = [
            round_ctx_build($matches, (string) ($group['round_mode'] ?? 'rodada'), (string) ($group['deadline_mode'] ?? 'jogo'), $now),
            array_column($matches, null, 'id'),
        ];
    }
    return $cache[$key];
}

/** Jogos da temporada atual de uma liga (com os times), em ordem de início. Inclui adiados. */
function league_season_matches(int $leagueId): array
{
    static $cache = [];
    if (!isset($cache[$leagueId])) {
        $stmt = db()->prepare('SELECT season_start FROM leagues WHERE id = ?');
        $stmt->execute([$leagueId]);
        $since = $stmt->fetchColumn() ?: '2000-01-01';
        $stmt = db()->prepare(
            'SELECT m.*, l.name AS league_name, ' . team_columns_sql() . '
             FROM matches m
             JOIN leagues l ON l.id = m.league_id
             ' . team_joins_sql() . '
             WHERE m.league_id = ? AND m.kickoff_at >= ?
             ORDER BY m.kickoff_at, m.id'
        );
        $stmt->execute([$leagueId, $since]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        $cache[$leagueId] = $rows;
    }
    return $cache[$leagueId];
}

/** Colunas dos dois times (escudo, cores, sigla) para os SELECTs de jogos. Ver team_badge(). */
function team_columns_sql(): string
{
    return 'ht.logo_url AS home_logo, ht.color AS home_color, ht.alt_color AS home_color2, ht.abbreviation AS home_abbr, ht.short_name AS home_short,
            at.logo_url AS away_logo, at.color AS away_color, at.alt_color AS away_color2, at.abbreviation AS away_abbr, at.short_name AS away_short';
}

function team_joins_sql(string $alias = 'm'): string
{
    return "LEFT JOIN teams ht ON ht.id = $alias.home_team_id LEFT JOIN teams at ON at.id = $alias.away_team_id";
}
