<?php
// Regras e funções do bolão. Todas as páginas do bolão carregam este arquivo.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

date_default_timezone_set('America/Sao_Paulo');

const PT_DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];


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

/** Palpites são aceitos até o início do jogo. */
function is_open(array $match): bool
{
    return strtotime($match['kickoff_at']) > time();
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
         WHERE p.group_id = ? AND m.home_score IS NOT NULL'
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

function group_ranking(int $groupId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, COALESCE(SUM(p.points), 0) AS total
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN predictions p ON p.user_id = u.id AND p.group_id = gm.group_id
         WHERE gm.group_id = ?
         GROUP BY u.id, u.name
         ORDER BY total DESC, u.name ASC'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/**
 * Posição com empate compartilhado: 2º, 2º, 4º.
 * Recebe o resultado de group_ranking() (já ordenado) e acrescenta 'pos'.
 */
function ranking_with_positions(array $ranking): array
{
    $out = [];
    $pos = 0;
    $prevTotal = null;
    foreach ($ranking as $i => $row) {
        $total = (int) $row['total'];
        if ($prevTotal === null || $total !== $prevTotal) {
            $pos = $i + 1;
            $prevTotal = $total;
        }
        $row['total'] = $total;
        $row['pos'] = $pos;
        $out[] = $row;
    }
    return $out;
}

/** Monta "?, ?, ?" para uma lista de IDs. */
function placeholders(array $items): string
{
    return implode(',', array_fill(0, count($items), '?'));
}
