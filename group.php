<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/group_views.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? 0);
$group = require_member($groupId, (int) $user['id']);
$uid = (int) $user['id'];
$isOwner = (int) $group['owner_id'] === $uid;
$now = time();

$tabs = $isOwner
    ? ['palpites' => 'Palpites', 'ranking' => 'Ranking', 'participantes' => 'Participantes', 'configurar' => 'Configurar']
    : ['palpites' => 'Palpites', 'ranking' => 'Ranking', 'participantes' => 'Participantes', 'regras' => 'Regras'];

$tab = (string) ($_GET['tab'] ?? 'palpites');
if ($tab === 'configurar' && !$isOwner) {
    flash_set('alert', 'Só o organizador pode alterar as configurações deste bolão.');
    redirect('group.php?id=' . $groupId . '&tab=regras');
}
if (!isset($tabs[$tab])) {
    $tab = 'palpites';
}

// Ligas e nomes
$leagueIds = group_league_ids($groupId);
$in = placeholders($leagueIds);
$stmt = db()->prepare("SELECT name FROM leagues WHERE id IN ($in) ORDER BY name");
$stmt->execute($leagueIds);
$leagueNames = array_column($stmt->fetchAll(), 'name');

// Todos os jogos das ligas do bolão (para as rodadas e o estado de cada uma)
$stmt = db()->prepare(
    "SELECT m.id, m.round, m.kickoff_at, m.home_team, m.away_team, m.home_score, m.away_score, l.name AS league_name
     FROM matches m
     JOIN leagues l ON l.id = m.league_id
     WHERE m.league_id IN ($in)
     ORDER BY m.kickoff_at, m.id"
);
$stmt->execute($leagueIds);
$allMatches = $stmt->fetchAll();

$stmt = db()->prepare('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?');
$stmt->execute([$uid, $groupId]);
$mine = [];
foreach ($stmt->fetchAll() as $p) {
    $mine[(int) $p['match_id']] = $p;
}

$rounds = [];
$roundStats = [];
$firstUnfinished = null;
foreach ($allMatches as $m) {
    $r = (int) $m['round'];
    $rounds[$r] = $r;
    if (!isset($roundStats[$r])) {
        $roundStats[$r] = ['total' => 0, 'finished' => 0, 'open' => 0, 'pending' => 0];
    }
    $roundStats[$r]['total']++;
    if ($m['home_score'] !== null) {
        $roundStats[$r]['finished']++;
    } else {
        if ($firstUnfinished === null) {
            $firstUnfinished = $r;
        }
        if (is_open($m)) {
            $roundStats[$r]['open']++;
            if (!isset($mine[(int) $m['id']])) {
                $roundStats[$r]['pending']++;
            }
        }
    }
}
ksort($rounds);
$rounds = array_values($rounds);

// Rodada: a pedida, ou a primeira com jogos ainda não encerrados, ou a última
$round = (int) ($_GET['round'] ?? 0);
if (!in_array($round, $rounds, true)) {
    $round = $firstUnfinished ?? ($rounds ? max($rounds) : 1);
}
$matches = array_values(array_filter($allMatches, function ($m) use ($round) {
    return (int) $m['round'] === $round;
}));

// Ranking e minha posição
$ranking = ranking_with_positions(group_ranking($groupId));
$me = null;
$above = null;
foreach ($ranking as $row) {
    if ((int) $row['id'] === $uid) {
        $me = $row;
    }
}
if ($me) {
    foreach ($ranking as $row) {
        if ($row['pos'] < $me['pos']) {
            $above = $row; // o último com posição menor é o mais próximo
        }
    }
}

// Mensagens deixadas por outra página (PRG)
$predictFlash = flash_take('predict');
$welcome = (bool) flash_take('welcome');
$configFlash = flash_take('config');

$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$inviteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'join_group.php?code=' . $group['invite_code'];

$ctx = [
    'user' => $user,
    'group' => $group,
    'groupId' => $groupId,
    'isOwner' => $isOwner,
    'tabs' => $tabs,
    'tab' => $tab,
    'leagueNames' => $leagueNames,
    'memberCount' => count($ranking),
    'me' => $me,
    'above' => $above,
    'ranking' => $ranking,
    'inviteUrl' => $inviteUrl,
    'rounds' => $rounds,
    'roundStats' => $roundStats,
    'round' => $round,
    'matches' => $matches,
    'mine' => $mine,
    'now' => $now,
    'predictFlash' => $predictFlash,
    'welcome' => $welcome,
    'configFlash' => $configFlash,
    'scored' => group_scored_counts($groupId),
];

page_start($group['name'], $user, 'wrap', 'bolao');
group_header($ctx);

switch ($tab) {
    case 'ranking':
        render_ranking($ctx);
        break;
    case 'participantes':
        render_participantes($ctx);
        break;
    case 'configurar':
        render_configurar($ctx);
        break;
    case 'regras':
        render_regras($ctx);
        break;
    default:
        render_palpites($ctx);
}

page_end();
