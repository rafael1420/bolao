<?php
// Regras e funções do bolão. Todas as páginas do bolão carregam este arquivo.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

date_default_timezone_set('America/Sao_Paulo');

// E-mail da conta que pode cadastrar jogos e resultados.
define('ADMIN_EMAIL', 'rafael.gomez2007@gmail.com');

function is_admin(?array $user): bool
{
    return $user !== null && strcasecmp($user['email'], ADMIN_EMAIL) === 0;
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Acesso negado.');
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

/** Monta "?, ?, ?" para uma lista de IDs. */
function placeholders(array $items): string
{
    return implode(',', array_fill(0, count($items), '?'));
}
