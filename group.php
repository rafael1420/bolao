<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/group_views.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? 0);
$group = require_member($groupId, (int) $user['id']);
$uid = (int) $user['id'];
$isOwner = (int) $group['owner_id'] === $uid;
$now = time();

$tabs = ['rodada' => 'Rodada', 'jogos' => 'Jogos', 'ranking' => 'Ranking', 'participantes' => 'Participantes'];
$tabs += $isOwner ? ['configurar' => 'Configurar'] : ['regras' => 'Regras'];

$tab = (string) ($_GET['tab'] ?? 'rodada');
if ($tab === 'palpites') {
    $tab = 'rodada'; // nome antigo da aba, de links já compartilhados
}
if ($tab === 'configurar' && !$isOwner) {
    flash_set('alert', 'Só o organizador pode alterar as configurações deste bolão.');
    redirect('group.php?id=' . $groupId . '&tab=regras');
}
if (!isset($tabs[$tab])) {
    $tab = 'rodada';
}

// Ligas do bolão
$leagueIds = group_league_ids($groupId);
$in = placeholders($leagueIds);
$stmt = db()->prepare("SELECT id, name, short_name, country, kind, logo_url FROM leagues WHERE id IN ($in) ORDER BY sort_order, name");
$stmt->execute($leagueIds);
$leagues = $stmt->fetchAll();
$leagueNames = array_column($leagues, 'name');
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));

// Campeonato da aba Rodada: o pedido, ou o que tem o próximo jogo, ou o primeiro
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

// Rodadas do campeonato escolhido: atual, abertas e bloqueadas, pela regra do bolão (includes/rounds.php).
// Adiados e cancelados ficam fora.
[$rctx, $byId] = round_context($leagueId, $group, $now);
$allMatches = array_values(array_filter($byId, fn ($m) => !is_void($m)));

$stmt = db()->prepare('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?');
$stmt->execute([$uid, $groupId]);
$mine = [];
foreach ($stmt->fetchAll() as $p) {
    $mine[(int) $p['match_id']] = $p;
}

$rounds = $rctx['numbers'];
$roundLabels = []; // número => nome ("Rodada 33", "Semifinal · ida")
$roundStats = [];
foreach ($rctx['rounds'] as $r => $info) {
    $roundLabels[$r] = $info['label'];
    $stat = ['total' => count($info['ids']), 'finished' => 0, 'open' => 0, 'pending' => 0,
        'state' => round_ui_state($rctx, $r, $byId)];
    foreach ($info['ids'] as $id) {
        $m = $byId[$id];
        if ($m['home_score'] !== null) {
            $stat['finished']++;
        } elseif (can_predict($m, $rctx)) {
            $stat['open']++;
            if (!isset($mine[$id])) {
                $stat['pending']++;
            }
        }
    }
    $roundStats[$r] = $stat;
}

// Rodada: a pedida, ou a atual
$round = (int) ($_GET['round'] ?? 0);
if (!in_array($round, $rounds, true)) {
    $round = $rctx['current'] ?? ($rounds ? max($rounds) : 1);
}
$matches = [];
foreach ($rctx['rounds'][$round]['ids'] ?? [] as $id) {
    $matches[] = $byId[$id];
}

// Jogos remarcados de outras rodadas que caem na semana da rodada atual (só na visão da rodada atual).
$rescheduled = [];
if ($round === $rctx['current'] && !$rctx['over'] && isset($rctx['rounds'][$round])) {
    $cur = $rctx['rounds'][$round];
    $from = (int) $cur['start'] - 86400;
    $until = $rctx['horizon'] ?? ((int) $cur['end'] + ROUND_LATE_MARGIN);
    foreach ($rctx['where'] as $id => [$origin, $isResched]) {
        $t = strtotime($byId[$id]['kickoff_at']);
        if ($isResched && $origin !== $round && $t >= $from && $t <= $until) {
            $rescheduled[] = $byId[$id] + ['origin_label' => $roundLabels[$origin] ?? ''];
        }
    }
    usort($rescheduled, fn ($a, $b) => strcmp($a['kickoff_at'], $b['kickoff_at']));
}

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

// Palpites de todos nos jogos já fechados desta rodada (nunca dos que ainda não começaram)
$others = $tab === 'rodada' ? closed_match_predictions($groupId, array_merge($matches, $rescheduled)) : [];

// Meus pontos e minha posição na rodada mostrada (cabeçalho da rodada encerrada)
$roundMe = null;
if ($tab === 'rodada' && ($roundStats[$round]['finished'] ?? 0) > 0) {
    foreach (ranking_with_positions(group_ranking($groupId, $round, $leagueId)) as $row) {
        if ((int) $row['id'] === $uid) {
            $roundMe = $row;
        }
    }
}

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
    'rctx' => $rctx,                   // contexto das rodadas (includes/rounds.php)
    'matches' => $matches,
    'rescheduled' => $rescheduled,     // remarcados de outras rodadas que caem nesta semana
    'roundMe' => $roundMe,             // minha linha no ranking da rodada mostrada
    'leagueName' => (string) (array_column($leagues, 'name', 'id')[$leagueId] ?? ''),
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
    case 'jogos':
        render_jogos($ctx);
        break;
    default:
        render_palpites($ctx);
}

page_end();
