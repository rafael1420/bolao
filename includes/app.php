<?php
// Regras e funções do bolão. Todas as páginas do bolão carregam este arquivo.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/leagues.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/rounds.php';

date_default_timezone_set('America/Sao_Paulo');

const PT_DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

// Erro inesperado (banco fora do ar, bug): registra no log e mostra uma página amigável.
set_exception_handler(function ($ex) {
    error_log('[bolao] ' . $ex);
    if (defined('BOLAO_API')) {
        // API (api/index.php): erro em JSON, que o app React mostra como aviso.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $msg = (defined('DEBUG') && DEBUG) ? (string) $ex : 'Não foi possível concluir agora. Tente de novo em instantes.';
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (defined('DEBUG') && DEBUG) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<pre>' . e((string) $ex) . '</pre>';
        return;
    }
    if (headers_sent()) {
        echo '<p>Algo deu errado. Tente de novo em instantes.</p>';
        return;
    }
    fail_page(500, 'Algo deu errado', 'Não foi possível concluir agora. Tente de novo em instantes.', null, [['index.php', 'Voltar ao início']]);
});

// Colunas e tabelas novas, caso a migração não tenha sido importada; e o catálogo de ligas.
ensure_schema();

// Jogos e placares automáticos (ESPN): roda depois que a página é entregue. Ver includes/sync.php.
sync_schedule();

/**
 * Confere a versão do banco (uma consulta por página). Desatualizado: cria o que falta
 * (sync_migrate) com uma trava, para duas páginas não alterarem as tabelas ao mesmo tempo.
 * Depois garante que as ligas do catálogo existem (leagues_seed, só quando o catálogo muda).
 */
function ensure_schema(): void
{
    $state = sync_state();
    if (($state['schema'] ?? '') !== SYNC_SCHEMA) {
        $pdo = db();
        if ((int) $pdo->query("SELECT GET_LOCK('bolao_migrate', 5)")->fetchColumn() === 1) {
            try {
                sync_migrate();
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('bolao_migrate')");
            }
        }
        $state = sync_state();
    }
    if (function_exists('leagues_seed')) {
        leagues_seed($state['catalog'] ?? '');
    }
}


function is_admin(?array $user): bool
{
    return $user !== null && strcasecmp($user['email'], ADMIN_EMAIL) === 0;
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        fail_page(403, 'Acesso negado', 'Só a administração do site pode abrir esta página.', $user, [['index.php', 'Meus bolões']]);
    }
    return $user;
}

/** Palpites são aceitos até o início do jogo (e nunca depois de lançado o placar, nem em jogo adiado). */
function is_open(array $match): bool
{
    if (isset($match['home_score']) && $match['home_score'] !== null) {
        return false;
    }
    if (is_void($match) || in_array($match['status'] ?? null, ['in', 'post'], true)) {
        return false; // a ESPN já marcou como começado ou encerrado
    }
    return strtotime($match['kickoff_at']) > time();
}

/** Nome da rodada: a fase nas copas ("Semifinal"), o número nas ligas ("Rodada 33"). */
function round_label(array $match): string
{
    return ($match['round_label'] ?? null) ?: 'Rodada ' . (int) $match['round'];
}

/** Jogo adiado ou cancelado, que não entra no bolão. */
function is_void(array $match): bool
{
    return ($match['status'] ?? null) === 'void';
}

/** Jogo em andamento, segundo a sincronização (placar ao vivo em live_home_score/live_away_score). */
function is_live(array $match): bool
{
    return ($match['home_score'] ?? null) === null && ($match['status'] ?? null) === 'in';
}

/** Trecho de SQL que deixa de fora os jogos adiados ou cancelados. $alias é a tabela de matches. */
function not_void_sql(string $alias = 'm'): string
{
    return "($alias.status IS NULL OR $alias.status <> 'void')";
}

function fmt_kickoff(string $datetime): string
{
    return date('d/m H:i', strtotime($datetime));
}

/**
 * Aceita só um caminho relativo para uma página .php deste site (ex.: join_group.php?code=abc).
 * Rejeita "://", "//", caminho absoluto e qualquer coisa fora do padrão.
 */
function safe_next($next): ?string
{
    if (!is_string($next) || $next === '') {
        return null;
    }
    if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9=&._-]{0,200})?$/', $next)) {
        return null;
    }
    return $next;
}

/** Hora no formato da casa: "16h" ou "18h30". */
function kick_clock(int $ts): string
{
    $minutes = (int) date('i', $ts);
    $hour = (int) date('G', $ts);
    return $minutes === 0 ? $hour . 'h' : $hour . 'h' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
}

/** Dia e hora do jogo: "sáb, 16h". Depois de 6 dias, mostra a data. */
function kick_day_clock(int $ts, int $now): string
{
    $diff = $ts - $now;
    if ($diff >= 6 * 86400) {
        return date('d/m', $ts) . ', ' . kick_clock($ts);
    }
    return PT_DAYS[(int) date('w', $ts)] . ', ' . kick_clock($ts);
}

/**
 * Texto do prazo de um jogo. Igual ao que o JavaScript atualiza a cada 30 s.
 * Cartão: "Fecha em 2 h 14 min". Cabeçalho ($first): "em 2 h 14 min".
 */
function kickoff_phrase(int $kick, int $now, bool $first = false): string
{
    $diff = $kick - $now;
    if ($diff <= 0) {
        return ($first ? 'fechou' : 'Fechou') . ' às ' . kick_clock($kick);
    }

    if ($diff >= 86400) {
        $core = kick_day_clock($kick, $now);
    } elseif ($diff >= 3600) {
        $h = intdiv($diff, 3600);
        $m = intdiv($diff % 3600, 60);
        $core = 'em ' . $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
    } elseif ($diff >= 60) {
        $core = 'em ' . intdiv($diff, 60) . ' min';
    } else {
        $core = 'em menos de 1 min';
    }

    return $first ? $core : ('Fecha ' . $core);
}

/** Exemplo de pontuação em texto: palpite 2 x 1 e resultado 3 x 2 (vencedor e saldo certos). */
function points_example(array $rules): string
{
    $total = calculate_points(2, 1, 3, 2, $rules);
    return 'Palpite 2 × 1 e resultado 3 × 2: acertou o vencedor (' . (int) $rules['winner_points']
        . ') e o saldo (+' . (int) $rules['goal_diff_points'] . ') = ' . plural($total, 'ponto', 'pontos') . '.';
}

/** Quantos palpites já foram pontuados neste bolão (base do aviso de recálculo). */
function group_scored_counts(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(p.id) AS preds, COUNT(DISTINCT m.id) AS matches
         FROM predictions p
         JOIN matches m ON m.id = p.match_id
         WHERE p.group_id = ? AND m.home_score IS NOT NULL AND ' . not_void_sql('m')
    );
    $stmt->execute([$groupId]);
    $row = $stmt->fetch();
    return ['preds' => (int) $row['preds'], 'matches' => (int) $row['matches']];
}

function plural(int $n, string $singular, string $plural): string
{
    return $n . ' ' . ($n === 1 ? $singular : $plural);
}

/**
 * Pontuação de um palpite:
 * - placar exato: exact_points (e nada mais)
 * - acertou o vencedor ou o empate: winner_points
 * - acertou também o saldo de gols: +goal_diff_points
 */
function calculate_points(int $predHome, int $predAway, int $homeScore, int $awayScore, array $rules): int
{
    if ($predHome === $homeScore && $predAway === $awayScore) {
        return (int) $rules['exact_points'];
    }

    if (($predHome <=> $predAway) !== ($homeScore <=> $awayScore)) {
        return 0;
    }

    $points = (int) $rules['winner_points'];
    if (($predHome - $predAway) === ($homeScore - $awayScore)) {
        $points += (int) $rules['goal_diff_points'];
    }
    return $points;
}

/** Explica de onde vieram os pontos, para mostrar ao participante. */
function points_reason(int $predHome, int $predAway, int $homeScore, int $awayScore): string
{
    if ($predHome === $homeScore && $predAway === $awayScore) {
        return 'Placar exato';
    }
    if (($predHome <=> $predAway) !== ($homeScore <=> $awayScore)) {
        return 'Nenhum acerto';
    }
    if (($predHome - $predAway) === ($homeScore - $awayScore)) {
        return 'Vencedor e saldo';
    }
    return 'Vencedor ou empate';
}

/** Recalcula pontos dos palpites que casam com $where (só jogos já encerrados). */
function recalculate_points(string $where, array $params): void
{
    $stmt = db()->prepare(
        "SELECT p.id, p.home_pred, p.away_pred, m.home_score, m.away_score,
                g.exact_points, g.winner_points, g.goal_diff_points
         FROM predictions p
         JOIN matches m ON m.id = p.match_id
         JOIN bolao_groups g ON g.id = p.group_id
         WHERE $where"
    );
    $stmt->execute($params);

    $update = db()->prepare('UPDATE predictions SET points = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $row) {
        $points = $row['home_score'] === null
            ? null
            : calculate_points(
                (int) $row['home_pred'],
                (int) $row['away_pred'],
                (int) $row['home_score'],
                (int) $row['away_score'],
                $row
            );
        $update->execute([$points, $row['id']]);
    }
}

function recalculate_match(int $matchId): void
{
    recalculate_points('p.match_id = ?', [$matchId]);
}

function recalculate_group(int $groupId): void
{
    recalculate_points('p.group_id = ?', [$groupId]);
}

function require_member(int $groupId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT g.* FROM bolao_groups g
         JOIN group_members m ON m.group_id = g.id
         WHERE g.id = ? AND m.user_id = ?'
    );
    $stmt->execute([$groupId, $userId]);
    $group = $stmt->fetch();

    if (!$group) {
        flash_set('alert', 'Você não está neste bolão, ou ele não existe mais.');
        redirect('index.php');
    }
    return $group;
}

/** IDs das ligas do grupo. Nunca vazio: o grupo sempre tem pelo menos uma liga. */
function group_league_ids(int $groupId): array
{
    $stmt = db()->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'league_id'));
    return $ids ?: [0];
}

/**
 * Ranking de vários bolões de uma vez: [group_id => linhas ordenadas].
 * Cada linha: id, name, total, exact (placares exatos), outcomes (acertou vencedor ou empate,
 * incluindo os exatos), scored (palpites que valeram pontos), played (palpites já avaliados).
 * Só contam jogos encerrados das ligas atuais do bolão. $round limita a uma rodada.
 * Ordem: total, exatos, acertos, nome.
 */
function group_rankings(array $groupIds, ?int $round = null, ?int $leagueId = null): array
{
    $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
    if (!$groupIds) {
        return [];
    }
    $in = placeholders($groupIds);
    $roundSql = ($round !== null ? ' AND m.round = ?' : '') . ($leagueId !== null ? ' AND m.league_id = ?' : '');

    $stmt = db()->prepare(
        "SELECT gm.group_id, u.id, u.name,
                COALESCE(SUM(s.points), 0)   AS total,
                COALESCE(SUM(s.exact), 0)    AS exact,
                COALESCE(SUM(s.outcome), 0)  AS outcomes,
                COALESCE(SUM(s.points > 0), 0) AS scored,
                COUNT(s.id)                  AS played
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN (
             SELECT p.id, p.user_id, p.group_id, p.points,
                    (p.home_pred = m.home_score AND p.away_pred = m.away_score) AS exact,
                    ((p.home_pred > p.away_pred) - (p.home_pred < p.away_pred))
                      = ((m.home_score > m.away_score) - (m.home_score < m.away_score)) AS outcome
             FROM predictions p
             JOIN matches m ON m.id = p.match_id
             JOIN group_leagues gl ON gl.group_id = p.group_id AND gl.league_id = m.league_id
             WHERE p.group_id IN ($in) AND m.home_score IS NOT NULL AND " . not_void_sql('m') . "$roundSql
         ) s ON s.user_id = gm.user_id AND s.group_id = gm.group_id
         WHERE gm.group_id IN ($in)
         GROUP BY gm.group_id, u.id, u.name
         ORDER BY gm.group_id, total DESC, exact DESC, outcomes DESC, u.name ASC"
    );
    $params = $groupIds;
    if ($round !== null) {
        $params[] = $round;
    }
    if ($leagueId !== null) {
        $params[] = $leagueId;
    }
    $stmt->execute(array_merge($params, $groupIds));

    $out = array_fill_keys($groupIds, []);
    foreach ($stmt->fetchAll() as $row) {
        $gid = (int) $row['group_id'];
        unset($row['group_id']);
        foreach (['total', 'exact', 'outcomes', 'scored', 'played'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $out[$gid][] = $row;
    }
    return $out;
}

/** Ranking geral do bolão (ou de uma rodada de um campeonato, com $round e $leagueId). Ver group_rankings(). */
function group_ranking(int $groupId, ?int $round = null, ?int $leagueId = null): array
{
    return group_rankings([$groupId], $round, $leagueId)[$groupId] ?? [];
}

/**
 * Posição com empate compartilhado: 2º, 2º, 4º.
 * Recebe o resultado de group_ranking() (já ordenado) e acrescenta 'pos'.
 * Só empata quem tem os mesmos pontos, placares exatos e acertos.
 */
function ranking_with_positions(array $ranking): array
{
    $out = [];
    $pos = 0;
    $prevKey = null;
    foreach ($ranking as $i => $row) {
        $row['total'] = (int) $row['total'];
        $key = $row['total'] . '|' . (int) ($row['exact'] ?? 0) . '|' . (int) ($row['outcomes'] ?? 0);
        if ($prevKey === null || $key !== $prevKey) {
            $pos = $i + 1;
            $prevKey = $key;
        }
        $row['pos'] = $pos;
        $out[] = $row;
    }
    return $out;
}

/**
 * Palpites de todos os participantes nos jogos já fechados (o início já passou).
 * Jogos ainda abertos nunca entram: os palpites alheios ficam escondidos até fechar.
 * Retorna [match_id => [linhas]]; cada linha: user_id, name, home_pred, away_pred
 * (null = não palpitou), points (null = sem resultado ainda) e reason.
 * Ordem: mais pontos primeiro, depois nome.
 */
function closed_match_predictions(int $groupId, array $matches): array
{
    $closed = [];
    foreach ($matches as $m) {
        if (!is_open($m) && !is_void($m)) {
            $closed[(int) $m['id']] = $m;
        }
    }
    if (!$closed) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.name FROM group_members gm JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ? ORDER BY u.name'
    );
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll();

    $ids = array_keys($closed);
    $stmt = db()->prepare(
        'SELECT match_id, user_id, home_pred, away_pred, points FROM predictions
         WHERE group_id = ? AND match_id IN (' . placeholders($ids) . ')'
    );
    $stmt->execute(array_merge([$groupId], $ids));
    $preds = [];
    foreach ($stmt->fetchAll() as $p) {
        $preds[(int) $p['match_id']][(int) $p['user_id']] = $p;
    }

    $out = [];
    foreach ($closed as $matchId => $m) {
        $finished = $m['home_score'] !== null;
        $rows = [];
        foreach ($members as $member) {
            $p = $preds[$matchId][(int) $member['id']] ?? null;
            $rows[] = [
                'user_id' => (int) $member['id'],
                'name' => $member['name'],
                'home_pred' => $p ? (int) $p['home_pred'] : null,
                'away_pred' => $p ? (int) $p['away_pred'] : null,
                'points' => ($p && $finished) ? (int) $p['points'] : null,
                'reason' => ($p && $finished)
                    ? points_reason((int) $p['home_pred'], (int) $p['away_pred'], (int) $m['home_score'], (int) $m['away_score'])
                    : ($p ? 'Aguardando o resultado' : 'Não palpitou'),
            ];
        }
        usort($rows, function ($a, $b) {
            $pa = $a['points'] ?? -1;
            $pb = $b['points'] ?? -1;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ha = $a['home_pred'] === null ? 1 : 0;
            $hb = $b['home_pred'] === null ? 1 : 0;
            if ($ha !== $hb) {
                return $ha <=> $hb;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        $out[$matchId] = $rows;
    }
    return $out;
}

/**
 * Rodadas que já têm pelo menos um jogo encerrado nas ligas do bolão (para o seletor do ranking).
 * Cada linha: key ("liga-rodada"), league_id, round, label ("Rodada 33" ou, com mais de uma liga,
 * "Copa do Brasil · Semifinal"). Da mais recente para a mais antiga.
 */
function group_scored_rounds(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT m.league_id, m.round, MAX(m.round_label) AS round_label, l.name AS league_name,
                MAX(m.kickoff_at) AS last_kick
         FROM matches m
         JOIN group_leagues gl ON gl.league_id = m.league_id
         JOIN leagues l ON l.id = m.league_id
         WHERE gl.group_id = ? AND m.home_score IS NOT NULL AND ' . not_void_sql('m') . '
         GROUP BY m.league_id, m.round, l.name
         ORDER BY last_kick DESC'
    );
    $stmt->execute([$groupId]);
    $rows = $stmt->fetchAll();
    $multi = count(array_unique(array_column($rows, 'league_id'))) > 1;

    $out = [];
    foreach ($rows as $row) {
        $label = round_label($row);
        $out[] = [
            'key' => (int) $row['league_id'] . '-' . (int) $row['round'],
            'league_id' => (int) $row['league_id'],
            'round' => (int) $row['round'],
            'label' => $multi ? $row['league_name'] . ' · ' . $label : $label,
            'round_name' => $label,
        ];
    }
    return $out;
}

/**
 * Para cada bolão: quantos jogos dá para palpitar agora e ainda não têm meu palpite ('pending') e o
 * próximo prazo de um jogo palpitável ('next', timestamp ou null). Segue a regra de rodada de cada bolão.
 * Poucas consultas no total: os jogos de todas as ligas envolvidas (de 20 dias atrás a 90 à frente)
 * e os meus palpites; o contexto de cada liga é montado uma vez por modo.
 */
function group_predictable(array $groups, int $userId, int $now): array
{
    $groupIds = array_map(fn ($g) => (int) $g['id'], $groups);
    if (!$groupIds) {
        return [];
    }
    $stmt = db()->prepare('SELECT group_id, league_id FROM group_leagues WHERE group_id IN (' . placeholders($groupIds) . ')');
    $stmt->execute($groupIds);
    $leaguesOf = [];
    foreach ($stmt->fetchAll() as $row) {
        $leaguesOf[(int) $row['group_id']][] = (int) $row['league_id'];
    }
    $leagueIds = array_values(array_unique(array_merge([], ...array_values($leaguesOf ?: [[]]))));
    $out = array_fill_keys($groupIds, ['pending' => 0, 'next' => null]);
    if (!$leagueIds) {
        return $out;
    }

    $stmt = db()->prepare(
        'SELECT id, league_id, round, round_label, kickoff_at, home_score, away_score, status FROM matches
         WHERE league_id IN (' . placeholders($leagueIds) . ') AND kickoff_at BETWEEN ? AND ?
         ORDER BY kickoff_at, id'
    );
    $stmt->execute(array_merge($leagueIds, [date('Y-m-d H:i:s', $now - 20 * 86400), date('Y-m-d H:i:s', $now + 90 * 86400)]));
    $byLeague = [];
    foreach ($stmt->fetchAll() as $m) {
        $byLeague[(int) $m['league_id']][] = $m;
    }

    $stmt = db()->prepare(
        'SELECT p.group_id, p.match_id FROM predictions p JOIN matches m ON m.id = p.match_id
         WHERE p.user_id = ? AND p.group_id IN (' . placeholders($groupIds) . ') AND m.kickoff_at > ?'
    );
    $stmt->execute(array_merge([$userId], $groupIds, [date('Y-m-d H:i:s', $now)]));
    $picked = [];
    foreach ($stmt->fetchAll() as $row) {
        $picked[(int) $row['group_id']][(int) $row['match_id']] = true;
    }

    $ctxCache = [];
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $mode = (string) ($g['round_mode'] ?? 'rodada');
        $deadline = (string) ($g['deadline_mode'] ?? 'jogo');
        foreach ($leaguesOf[$gid] ?? [] as $leagueId) {
            $key = $leagueId . '|' . $mode . '|' . $deadline;
            if (!isset($ctxCache[$key])) {
                $ctxCache[$key] = round_ctx_build($byLeague[$leagueId] ?? [], $mode, $deadline, $now);
            }
            $ctx = $ctxCache[$key];
            foreach ($byLeague[$leagueId] ?? [] as $m) {
                if (!can_predict($m, $ctx)) {
                    continue;
                }
                if (!isset($picked[$gid][(int) $m['id']])) {
                    $out[$gid]['pending']++;
                }
                // Prazo: o início do jogo, ou o 1º jogo da rodada com prazo por rodada.
                $close = strtotime($m['kickoff_at']);
                [$number, $resched] = $ctx['where'][(int) $m['id']] ?? [(int) $m['round'], false];
                if (!$resched && $ctx['deadline'] === 'rodada' && !empty($ctx['rounds'][$number]['start'])) {
                    $close = min($close, (int) $ctx['rounds'][$number]['start']);
                }
                $out[$gid]['next'] = $out[$gid]['next'] === null ? $close : min($out[$gid]['next'], $close);
            }
        }
    }
    return $out;
}

/**
 * Pontos de cada participante em cada rodada (jogos encerrados das ligas atuais do bolão), numa consulta.
 * Devolve [user_id => ["liga-rodada" => pontos]]. Base da visão "Rodada a rodada" do ranking.
 */
function group_points_by_round(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT p.user_id, m.league_id, m.round, COALESCE(SUM(p.points), 0) AS pts
         FROM predictions p
         JOIN matches m ON m.id = p.match_id
         JOIN group_leagues gl ON gl.group_id = p.group_id AND gl.league_id = m.league_id
         WHERE p.group_id = ? AND m.home_score IS NOT NULL AND ' . not_void_sql('m') . '
         GROUP BY p.user_id, m.league_id, m.round'
    );
    $stmt->execute([$groupId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['user_id']][(int) $row['league_id'] . '-' . (int) $row['round']] = (int) $row['pts'];
    }
    return $out;
}

/**
 * Passa a organização do bolão para outro participante, numa transação.
 * Retorna false se o novo organizador não estiver no bolão.
 */
function transfer_group_ownership(int $groupId, int $fromUserId, int $toUserId): bool
{
    if ($fromUserId === $toUserId) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT owner_id FROM bolao_groups WHERE id = ? FOR UPDATE');
        $stmt->execute([$groupId]);
        $owner = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $toUserId]);
        if ((int) $owner !== $fromUserId || !$stmt->fetch()) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare('UPDATE bolao_groups SET owner_id = ? WHERE id = ?')->execute([$toUserId, $groupId]);
        $pdo->prepare("UPDATE group_members SET role = 'member' WHERE group_id = ? AND user_id = ?")
            ->execute([$groupId, $fromUserId]);
        $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?")
            ->execute([$groupId, $toUserId]);
        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Monta "?, ?, ?" para uma lista de IDs. */
function placeholders(array $items): string
{
    return implode(',', array_fill(0, count($items), '?'));
}

/* ---------- Recuperação de senha ---------- */

// Link pedido pela própria pessoa vale 1 h; link gerado pela administração vale 24 h.
const RESET_TTL_SECONDS = 3600;
const RESET_ADMIN_TTL_SECONDS = 86400;
// Pedidos de link: no máximo 3 por conta e 10 por IP a cada hora.
const RESET_MAX_PER_USER = 3;
const RESET_MAX_PER_IP = 10;

/** Endereço público do site, terminado em "/". Usa SITE_URL do config.php quando existe. */
function site_url(): string
{
    if (defined('SITE_URL') && SITE_URL !== '') {
        return rtrim(SITE_URL, '/') . '/';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . BASE_URL;
}

function reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Invalida os links de redefinição ainda não usados de uma conta. */
function invalidate_password_resets(int $userId): void
{
    db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([sql_time(time()), $userId]);
}

/**
 * Cria um link de redefinição e devolve o token em texto (só existe aqui; o banco guarda o hash).
 * Links anteriores da mesma conta deixam de valer.
 */
function create_password_reset(int $userId, string $createdBy = 'user'): string
{
    $token = bin2hex(random_bytes(32));
    $ttl = $createdBy === 'admin' ? RESET_ADMIN_TTL_SECONDS : RESET_TTL_SECONDS;
    invalidate_password_resets($userId);
    db()->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, reset_token_hash($token), sql_time(time() + $ttl), client_ip(), $createdBy === 'admin' ? 'admin' : 'user', sql_time(time())]);
    // Limpeza: links vencidos há mais de 30 dias.
    db()->prepare('DELETE FROM password_resets WHERE expires_at < ?')->execute([sql_time(time() - 30 * 86400)]);
    return $token;
}

function password_reset_url(string $token): string
{
    return site_url() . 'nova-senha?token=' . $token;
}

/** Este IP ainda pode pedir links? No máximo 10 por hora. */
function reset_ip_allowed(string $ip): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM password_resets WHERE requested_ip = ? AND created_by = 'user' AND created_at > ?");
    $stmt->execute([$ip, sql_time(time() - 3600)]);
    return (int) $stmt->fetchColumn() < RESET_MAX_PER_IP;
}

/** Esta conta ainda pode receber links? No máximo 3 por hora (evita encher a caixa de alguém). */
function reset_user_allowed(int $userId): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_by = 'user' AND created_at > ?");
    $stmt->execute([$userId, sql_time(time() - 3600)]);
    return (int) $stmt->fetchColumn() < RESET_MAX_PER_USER;
}

/** Link válido (existe, não venceu, não foi usado): devolve o pedido com o nome e e-mail da conta. */
function find_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT r.id, r.user_id, r.expires_at, u.name, u.email
         FROM password_resets r JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?'
    );
    $stmt->execute([reset_token_hash($token), sql_time(time())]);
    return $stmt->fetch() ?: null;
}

/**
 * Troca a senha usando o link (uso único, numa transação). Devolve o id da conta, ou null
 * se o link venceu ou já foi usado.
 */
function consume_password_reset(string $token, string $newPassword): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? FOR UPDATE'
        );
        $stmt->execute([reset_token_hash($token), sql_time(time())]);
        $reset = $stmt->fetch();
        if (!$reset) {
            $pdo->rollBack();
            return null;
        }
        $userId = (int) $reset['user_id'];
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        // Este e qualquer outro link pendente da conta deixam de valer.
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([sql_time(time()), $userId]);
        $pdo->commit();
        return $userId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Envia o link por e-mail com mail() (funciona na hospedagem da Hostinger). */
function send_password_reset_email(string $email, string $name, string $url): bool
{
    $host = (string) parse_url(site_url(), PHP_URL_HOST);
    $from = defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'no-reply@' . preg_replace('/^www\./', '', $host);
    $site = SITE_NAME;

    $subject = '=?UTF-8?B?' . base64_encode('Redefinir sua senha | ' . $site) . '?=';
    $body = "Olá, {$name}.\r\n\r\n"
        . "Recebemos um pedido para redefinir a senha da sua conta no {$site}.\r\n"
        . "Para criar uma nova senha, abra o link abaixo (vale por 1 hora e funciona uma vez só):\r\n\r\n"
        . $url . "\r\n\r\n"
        . "Se não foi você, ignore este e-mail. Sua senha continua a mesma.\r\n";
    $fromName = '=?UTF-8?B?' . base64_encode($site) . '?=';
    $headers = implode("\r\n", [
        'From: ' . $fromName . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: PHP',
    ]);

    // Evita quebra de cabeçalho com e-mail malformado.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return @mail($email, $subject, $body, $headers, '-f' . $from);
}

/* ---------- Importação de jogos em lote (administração) ---------- */

const IMPORT_MAX_LINES = 500;

/**
 * Lê uma data e hora em formatos comuns: 25/10/2026 16:00, 25/10/26 16h, 25/10 18h30,
 * 2026-10-25 16:00. Sem ano, usa o ano atual. Retorna "Y-m-d H:i:00" ou null.
 */
function parse_import_datetime(string $raw): ?string
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if (!preg_match('/^(\d{1,4})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?(?:,|\s|T)+(\d{1,2})(?:[:h](\d{2})?)?h?$/i', $raw, $m)) {
        return null;
    }
    if (strlen($m[1]) === 4) {
        // Formato ISO: ano-mês-dia
        if (($m[3] ?? '') === '') {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } else {
        [$day, $month] = [(int) $m[1], (int) $m[2]];
        $yearRaw = $m[3] ?? '';
        $year = $yearRaw === '' ? (int) date('Y') : (int) $yearRaw;
        if ($yearRaw !== '' && strlen($yearRaw) === 2) {
            $year += 2000;
        }
    }
    $hour = (int) $m[4];
    $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $year < 2000 || $year > 2100) {
        return null;
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
}

/**
 * Confere linha a linha um texto colado (rodada; data e hora; mandante; visitante).
 * Separador: ponto e vírgula, tabulação ou vírgula. Linhas vazias, com # ou o cabeçalho são ignoradas.
 * Cada linha volta com status: ok (será gravada), skip (já existe) ou error (com o motivo).
 */
function parse_match_import(string $text, int $leagueId): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $rows = [];
    $seen = [];

    $stmt = db()->prepare('SELECT round, home_team, away_team FROM matches WHERE league_id = ?');
    $stmt->execute([$leagueId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $m) {
        $existing[(int) $m['round'] . '|' . mb_strtolower($m['home_team']) . '|' . mb_strtolower($m['away_team'])] = true;
    }

    $counted = 0;
    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $counted++;
        if ($counted > IMPORT_MAX_LINES) {
            $rows[] = ['line' => $lineNo, 'raw' => $line, 'status' => 'error',
                'message' => 'Limite de ' . IMPORT_MAX_LINES . ' jogos por importação. Divida o texto em partes.'];
            break;
        }

        if (strpos($line, ';') !== false) {
            $parts = explode(';', $line);
        } elseif (strpos($line, "\t") !== false) {
            $parts = explode("\t", $line);
        } else {
            $parts = str_getcsv($line, ',', '"', '');
        }
        $parts = array_map(function ($p) {
            return trim((string) $p, " \t\"'");
        }, $parts);

        // Cabeçalho (ex.: "rodada;data;mandante;visitante")
        if ($counted === 1 && stripos($parts[0] ?? '', 'rodada') !== false) {
            $counted--;
            continue;
        }

        $row = ['line' => $lineNo, 'raw' => $line, 'round' => null, 'kickoff' => null,
            'home' => '', 'away' => '', 'status' => 'ok', 'message' => ''];
        $errors = [];

        if (count($parts) !== 4) {
            $errors[] = 'esperava 4 colunas (rodada; data e hora; mandante; visitante), achei ' . count($parts);
        } else {
            [$roundRaw, $dateRaw, $home, $away] = $parts;
            $row['home'] = $home;
            $row['away'] = $away;
            if (!ctype_digit($roundRaw) || (int) $roundRaw < 1 || (int) $roundRaw > 999) {
                $errors[] = 'rodada inválida (use um número de 1 a 999)';
            } else {
                $row['round'] = (int) $roundRaw;
            }
            $kickoff = parse_import_datetime($dateRaw);
            if ($kickoff === null) {
                $errors[] = 'data e hora inválidas (use 25/10/2026 16:00)';
            } else {
                $row['kickoff'] = $kickoff;
            }
            if ($home === '' || mb_strlen($home) > 100) {
                $errors[] = 'mandante vazio ou com mais de 100 letras';
            }
            if ($away === '' || mb_strlen($away) > 100) {
                $errors[] = 'visitante vazio ou com mais de 100 letras';
            }
            if ($home !== '' && mb_strtolower($home) === mb_strtolower($away)) {
                $errors[] = 'mandante e visitante iguais';
            }
        }

        if ($errors) {
            $row['status'] = 'error';
            $row['message'] = ucfirst(implode('; ', $errors)) . '.';
        } else {
            $key = $row['round'] . '|' . mb_strtolower($row['home']) . '|' . mb_strtolower($row['away']);
            if (isset($existing[$key])) {
                $row['status'] = 'skip';
                $row['message'] = 'Já cadastrado nesta liga e rodada. Será ignorado.';
            } elseif (isset($seen[$key])) {
                $row['status'] = 'skip';
                $row['message'] = 'Repetido no texto (linha ' . $seen[$key] . '). Será ignorado.';
            } else {
                $seen[$key] = $lineNo;
            }
        }
        $rows[] = $row;
    }

    $count = ['ok' => 0, 'skip' => 0, 'error' => 0];
    foreach ($rows as $r) {
        $count[$r['status']]++;
    }
    return ['rows' => $rows, 'count' => $count];
}

/** Grava as linhas "ok" de parse_match_import(), numa transação. Retorna quantas entraram. */
function save_match_import(array $parsed, int $leagueId): int
{
    $pdo = db();
    $insert = $pdo->prepare('INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)');
    $saved = 0;
    $pdo->beginTransaction();
    try {
        foreach ($parsed['rows'] as $r) {
            if ($r['status'] !== 'ok') {
                continue;
            }
            $insert->execute([$leagueId, $r['round'], $r['home'], $r['away'], $r['kickoff']]);
            $saved++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $saved;
}
