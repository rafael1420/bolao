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
$stmt = db()->prepare("SELECT id, name FROM leagues WHERE id IN ($in) ORDER BY name");
$stmt->execute($leagueIds);
$leagues = $stmt->fetchAll();
$leagueNames = array_column($leagues, 'name');
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));

// Campeonato da aba Palpites: o pedido, ou o que tem o próximo jogo, ou o primeiro
$leagueId = (int) ($_GET['league'] ?? 0);
if (!in_array($leagueId, $validLeagueIds, true)) {
    $stmt = db()->prepare(
        "SELECT league_id FROM matches
         WHERE league_id IN ($in) AND kickoff_at > ? AND " . not_void_sql('matches') . '
         ORDER BY kickoff_at LIMIT 1'
    );
    $stmt->execute(array_merge($leagueIds, [date('Y-m-d H:i:s', $now)]));
    $leagueId = (int) $stmt->fetchColumn() ?: ($validLeagueIds[0] ?? 0);
}

// Jogos do campeonato escolhido (para as rodadas e o estado de cada uma). Adiados e cancelados ficam fora.
$stmt = db()->prepare(
    'SELECT m.*, l.name AS league_name
     FROM matches m
     JOIN leagues l ON l.id = m.league_id
     WHERE m.league_id = ? AND ' . not_void_sql('m') . '
     ORDER BY m.kickoff_at, m.id'
);
$stmt->execute([$leagueId]);
$allMatches = $stmt->fetchAll();

$stmt = db()->prepare('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?');
$stmt->execute([$uid, $groupId]);
$mine = [];
foreach ($stmt->fetchAll() as $p) {
    $mine[(int) $p['match_id']] = $p;
}

$rounds = [];
$roundLabels = []; // número => nome ("Rodada 33", "Semifinal")
$roundStats = [];
$recent = $now - 3 * 86400;
$pendingRounds = [];
foreach ($allMatches as $m) {
    $r = (int) $m['round'];
    $rounds[$r] = $r;
    if (!isset($roundLabels[$r])) {
        $roundLabels[$r] = round_label($m);
    }
    if (!isset($roundStats[$r])) {
        $roundStats[$r] = ['total' => 0, 'finished' => 0, 'open' => 0, 'pending' => 0];
    }
    $roundStats[$r]['total']++;
    if ($m['home_score'] !== null) {
        $roundStats[$r]['finished']++;
    } else {
        // Jogo sem placar há mais de 3 dias (ex.: copa antiga) não prende a rodada padrão.
        if (strtotime($m['kickoff_at']) > $recent) {
            $pendingRounds[] = $r;
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
    $round = $pendingRounds ? min($pendingRounds) : ($rounds ? max($rounds) : 1);
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
        if ($row['pos'] < $me['pos'] && $row['total'] > $me['total']) {
            $above = $row; // o último com mais pontos é o mais próximo
        }
    }
}

// Ranking de uma rodada (aba Ranking, seletor "Geral / Rodada N"). Valor: "liga-rodada".
$scoredRounds = $tab === 'ranking' ? group_scored_rounds($groupId) : [];
$rankKey = (string) ($_GET['rodada'] ?? '');
$rankRound = null;
foreach ($scoredRounds as $sr) {
    if ($sr['key'] === $rankKey) {
        $rankRound = $sr;
    }
}
$roundRanking = $rankRound
    ? ranking_with_positions(group_ranking($groupId, $rankRound['round'], $rankRound['league_id']))
    : [];

// Palpites de todos nos jogos já fechados desta rodada (nunca dos abertos)
$others = $tab === 'palpites' ? closed_match_predictions($groupId, $matches) : [];

// Mensagens deixadas por outra página (PRG)
$predictFlash = flash_take('predict');
$welcome = (bool) flash_take('welcome');
$configFlash = flash_take('config');

$inviteUrl = site_url() . 'join_group.php?code=' . $group['invite_code'];

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
    'roundLabels' => $roundLabels,     // número da rodada => nome (round_label)
    'leagues' => $leagues,             // ligas do bolão (id, name), para as abas de campeonato
    'leagueId' => $leagueId,           // campeonato mostrado na aba Palpites
    'leagueQs' => '&amp;league=' . $leagueId,
    'roundStats' => $roundStats,
    'round' => $round,
    'matches' => $matches,
    'mine' => $mine,
    'now' => $now,
    'predictFlash' => $predictFlash,
    'welcome' => $welcome,
    'configFlash' => $configFlash,
    'scored' => group_scored_counts($groupId),
    'scoredRounds' => $scoredRounds,   // rodadas com jogo encerrado: key, league_id, round, label
    'rankRound' => $rankRound,         // null = ranking geral; senão a linha de scoredRounds escolhida
    'roundRanking' => $roundRanking,   // ranking só da rodada escolhida
    'others' => $others,               // [match_id => palpites de todos], só jogos fechados
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
