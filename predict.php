<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();

$groupId = (int) ($_POST['group_id'] ?? 0);
$round = (int) ($_POST['round'] ?? 0);

// Jogos enviados, das ligas do bolão. Quem decide se aceita é a regra de rodada (includes/rounds.php):
// o jogo não começou e a rodada dele está aberta para este bolão (ou é um jogo remarcado da semana).
$group = require_member($groupId, $user['id']);
$leagueIds = group_league_ids($groupId);
$leagueId = (int) ($_POST['league'] ?? 0);
if (!in_array($leagueId, $leagueIds, true)) {
    $leagueId = 0;
}
$preds = is_array($_POST['preds'] ?? null) ? $_POST['preds'] : [];
$ids = array_slice(array_values(array_filter(array_map('intval', array_keys($preds)))), 0, 200);
$matches = [];
if ($ids) {
    $stmt = db()->prepare(
        'SELECT * FROM matches
         WHERE id IN (' . placeholders($ids) . ') AND league_id IN (' . placeholders($leagueIds) . ')'
    );
    $stmt->execute(array_merge($ids, $leagueIds));
    foreach ($stmt->fetchAll() as $match) {
        $matches[(int) $match['id']] = $match;
    }
}
$now = time();

$upsert = db()->prepare(
    'INSERT INTO predictions (user_id, group_id, match_id, home_pred, away_pred)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE home_pred = VALUES(home_pred), away_pred = VALUES(away_pred)'
);

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
    [$ctx] = round_context((int) $match['league_id'], $group, $now);
    $status = predict_status($match, $ctx);
    if ($status['reason'] === 'void') {
        continue; // adiado ou cancelado: fora do bolão
    }
    $reason = null;
    if (!$status['ok']) {
        $reason = predict_reason_text($status, $ctx, $now);
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

redirect('group.php?id=' . $groupId . '&tab=rodada' . ($leagueId ? '&league=' . $leagueId : '') . '&round=' . $round);
