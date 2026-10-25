<?php
require_once __DIR__ . '/../includes/app.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verify_csrf();

$groupId = (int) ($_POST['group_id'] ?? 0);
$round = (int) ($_POST['round'] ?? 0);
require_member($groupId, $user['id']);

// Só aceita palpites de jogos desta rodada, das ligas do grupo, ainda abertos.
$leagueIds = group_league_ids($groupId);
$stmt = db()->prepare(
    'SELECT id, kickoff_at FROM matches
     WHERE league_id IN (' . placeholders($leagueIds) . ') AND round = ?'
);
$stmt->execute([...$leagueIds, $round]);

$allowed = [];
foreach ($stmt->fetchAll() as $match) {
    if (is_open($match)) {
        $allowed[(int) $match['id']] = true;
    }
}

$upsert = db()->prepare(
    'INSERT INTO predictions (user_id, group_id, match_id, home_pred, away_pred)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE home_pred = VALUES(home_pred), away_pred = VALUES(away_pred)'
);

$preds = is_array($_POST['preds'] ?? null) ? $_POST['preds'] : [];
foreach ($preds as $matchId => $score) {
    $matchId = (int) $matchId;
    if (!isset($allowed[$matchId]) || !is_array($score)) {
        continue;
    }

    $home = trim((string) ($score['home'] ?? ''));
    $away = trim((string) ($score['away'] ?? ''));
    if ($home === '' || $away === '' || !ctype_digit($home) || !ctype_digit($away)) {
        continue;
    }

    $homeGoals = (int) $home;
    $awayGoals = (int) $away;
    if ($homeGoals > 30 || $awayGoals > 30) {
        continue;
    }

    $upsert->execute([$user['id'], $groupId, $matchId, $homeGoals, $awayGoals]);
}

redirect('group.php?id=' . $groupId . '&round=' . $round . '&saved=1');
