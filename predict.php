<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();

$groupId = (int) ($_POST['group_id'] ?? 0);
$round = (int) ($_POST['round'] ?? 0);
require_member($groupId, $user['id']);

// Jogos desta rodada, do campeonato escolhido (que precisa ser do grupo). Só os abertos aceitam palpite.
$leagueIds = group_league_ids($groupId);
$leagueId = (int) ($_POST['league'] ?? 0);
if (in_array($leagueId, $leagueIds, true)) {
    $leagueIds = [$leagueId];
} else {
    $leagueId = 0;
}
$stmt = db()->prepare(
    'SELECT * FROM matches
     WHERE league_id IN (' . placeholders($leagueIds) . ') AND round = ?'
);
$stmt->execute([...$leagueIds, $round]);

$matches = [];
$allowed = [];
foreach ($stmt->fetchAll() as $match) {
    if (is_void($match)) {
        continue; // adiado ou cancelado: fora do bolão
    }
    $id = (int) $match['id'];
    $matches[$id] = $match;
    if (is_open($match)) {
        $allowed[$id] = true;
    }
}

$upsert = db()->prepare(
    'INSERT INTO predictions (user_id, group_id, match_id, home_pred, away_pred)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE home_pred = VALUES(home_pred), away_pred = VALUES(away_pred)'
);

$preds = is_array($_POST['preds'] ?? null) ? $_POST['preds'] : [];
$saved = 0;
$skipped = [];   // o que não entrou, com motivo, para mostrar na tela
$keep = [];      // o que a pessoa digitou nesses jogos, para não perder

foreach ($preds as $matchId => $score) {
    $matchId = (int) $matchId;
    if (!isset($matches[$matchId]) || !is_array($score)) {
        continue;
    }

    $match = $matches[$matchId];
    $home = trim((string) ($score['home'] ?? ''));
    $away = trim((string) ($score['away'] ?? ''));
    if ($home === '' && $away === '') {
        continue; // jogo deixado em branco: não é erro
    }

    $label = $match['home_team'] . ' × ' . $match['away_team'];
    $reason = null;
    if (!isset($allowed[$matchId])) {
        $reason = $match['home_score'] !== null ? 'o jogo já terminou' : 'o jogo já começou';
    } elseif ($home === '' || $away === '') {
        $reason = $home === '' ? 'falta o placar do mandante' : 'falta o placar do visitante';
    } elseif (!ctype_digit($home) || !ctype_digit($away) || (int) $home > 30 || (int) $away > 30) {
        $reason = 'use placares de 0 a 30';
    }

    if ($reason !== null) {
        $skipped[] = ['id' => $matchId, 'label' => $label, 'reason' => $reason];
        $keep[$matchId] = ['home' => $home, 'away' => $away];
        continue;
    }

    $upsert->execute([$user['id'], $groupId, $matchId, (int) $home, (int) $away]);
    $saved++;
}

flash_set('predict', [
    'saved' => $saved,
    'time' => kick_clock(time()),
    'skipped' => $skipped,
    'keep' => $keep,
]);

redirect('group.php?id=' . $groupId . ($leagueId ? '&league=' . $leagueId : '') . '&round=' . $round);
