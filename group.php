<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? 0);
$group = require_member($groupId, $user['id']);
$isOwner = (int) $group['owner_id'] === (int) $user['id'];

$leagueIds = group_league_ids($groupId);
$in = placeholders($leagueIds);

$stmt = db()->prepare("SELECT name FROM leagues WHERE id IN ($in) ORDER BY name");
$stmt->execute($leagueIds);
$leagueNames = array_column($stmt->fetchAll(), 'name');

$stmt = db()->prepare("SELECT DISTINCT round FROM matches WHERE league_id IN ($in) ORDER BY round");
$stmt->execute($leagueIds);
$rounds = array_map('intval', array_column($stmt->fetchAll(), 'round'));

// Rodada escolhida: a pedida ou a primeira com jogos ainda não encerrados
$round = (int) ($_GET['round'] ?? 0);
if ($round <= 0) {
    $stmt = db()->prepare("SELECT MIN(round) FROM matches WHERE league_id IN ($in) AND home_score IS NULL");
    $stmt->execute($leagueIds);
    $round = (int) $stmt->fetchColumn();
    if ($round <= 0) {
        $round = $rounds ? max($rounds) : 1;
    }
}

$stmt = db()->prepare(
    "SELECT m.*, l.name AS league_name
     FROM matches m
     JOIN leagues l ON l.id = m.league_id
     WHERE m.league_id IN ($in) AND m.round = ?
     ORDER BY m.kickoff_at, m.id"
);
$stmt->execute([...$leagueIds, $round]);
$matches = $stmt->fetchAll();

$stmt = db()->prepare('SELECT match_id, home_pred, away_pred, points FROM predictions WHERE user_id = ? AND group_id = ?');
$stmt->execute([$user['id'], $groupId]);
$mine = [];
foreach ($stmt->fetchAll() as $p) {
    $mine[(int) $p['match_id']] = $p;
}

$ranking = group_ranking($groupId);

$hasOpenMatch = false;
foreach ($matches as $m) {
    if (is_open($m)) {
        $hasOpenMatch = true;
    }
}

$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$inviteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . 'join_group.php?code=' . $group['invite_code'];
$prevRound = $round > 1 ? $round - 1 : null;
$nextRound = $rounds && $round < max($rounds) ? $round + 1 : null;

page_start($group['name'], $user, 'wrap');
?>
<div class="page-head">
    <div>
        <h1><?= e($group['name']) ?></h1>
        <p class="muted-left"><?= e(implode(', ', $leagueNames)) ?></p>
    </div>
    <div class="actions">
        <?php if ($isOwner): ?>
            <a class="btn secondary" href="members.php?id=<?= $groupId ?>">Membros</a>
            <a class="btn secondary" href="group_settings.php?id=<?= $groupId ?>">Configurar</a>
        <?php else: ?>
            <form method="post" action="leave_group.php" class="inline" onsubmit="return confirm('Sair deste bolão?');">
                <?= csrf_field() ?>
                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                <button type="submit" class="btn secondary">Sair do bolão</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (($_GET['error'] ?? '') === 'owner'): ?>
    <?php alert_box(['O organizador não pode sair do próprio bolão.']); ?>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <?php notice('Tudo salvo.'); ?>
<?php endif; ?>

<div class="grid-2">
    <section class="panel">
        <h2>Convite</h2>
        <p>Código: <strong><?= e($group['invite_code']) ?></strong></p>
        <label>Link para os amigos
            <input type="text" readonly value="<?= e($inviteUrl) ?>" onclick="this.select()">
        </label>
        <p class="muted-left small-text"><?= $group['password_hash'] ? 'Este bolão pede senha para entrar.' : 'Este bolão não pede senha.' ?></p>
    </section>

    <section class="panel">
        <h2>Pontuação</h2>
        <p>Placar exato: <strong><?= (int) $group['exact_points'] ?></strong> pontos</p>
        <p>Vencedor ou empate: <strong><?= (int) $group['winner_points'] ?></strong> pontos</p>
        <p>Saldo de gols certo: <strong>+<?= (int) $group['goal_diff_points'] ?></strong> pontos</p>
    </section>
</div>

<section class="panel">
    <div class="round-nav">
        <?php if ($prevRound): ?>
            <a class="btn secondary small" href="group.php?id=<?= $groupId ?>&amp;round=<?= $prevRound ?>">Rodada <?= $prevRound ?></a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>

        <span class="round-title">Rodada <?= $round ?></span>

        <?php if ($nextRound): ?>
            <a class="btn secondary small" href="group.php?id=<?= $groupId ?>&amp;round=<?= $nextRound ?>">Rodada <?= $nextRound ?></a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php if (!$matches): ?>
        <p class="muted-left">Nenhum jogo cadastrado para esta rodada.</p>
    <?php else: ?>
        <form method="post" action="predict.php">
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= $groupId ?>">
            <input type="hidden" name="round" value="<?= $round ?>">

            <ul class="matches">
            <?php foreach ($matches as $m):
                $id = (int) $m['id'];
                $pred = $mine[$id] ?? null;
                $open = is_open($m);
                $finished = $m['home_score'] !== null;
                $hasPred = $pred !== null;
            ?>
                <li class="match">
                    <div class="match-teams">
                        <span class="match-league"><?= e($m['league_name']) ?></span>
                        <span class="match-when"><?= e(fmt_kickoff($m['kickoff_at'])) ?></span>
                        <p class="team-line">
                            <span><?= e($m['home_team']) ?></span>
                            <span class="vs">×</span>
                            <span><?= e($m['away_team']) ?></span>
                        </p>
                    </div>

                    <div class="match-pick">
                        <?php if ($open): ?>
                            <div class="pick">
                                <label class="visually-hidden" for="h<?= $id ?>"><?= e($m['home_team']) ?></label>
                                <input id="h<?= $id ?>" class="score" type="number" inputmode="numeric" min="0" max="30"
                                       name="preds[<?= $id ?>][home]"
                                       value="<?= $hasPred ? (int) $pred['home_pred'] : '' ?>">
                                <span class="sep" aria-hidden="true">:</span>
                                <label class="visually-hidden" for="a<?= $id ?>"><?= e($m['away_team']) ?></label>
                                <input id="a<?= $id ?>" class="score" type="number" inputmode="numeric" min="0" max="30"
                                       name="preds[<?= $id ?>][away]"
                                       value="<?= $hasPred ? (int) $pred['away_pred'] : '' ?>">
                            </div>
                        <?php elseif ($hasPred): ?>
                            <span class="closed-pick"><?= (int) $pred['home_pred'] ?> : <?= (int) $pred['away_pred'] ?></span>
                            <span class="lock-note">Palpite fechado</span>
                        <?php else: ?>
                            <span class="closed-pick none">Sem palpite</span>
                        <?php endif; ?>
                    </div>

                    <div class="match-status">
                        <span class="match-result">
                            <?php if ($finished): ?>
                                Resultado <strong><?= (int) $m['home_score'] ?> : <?= (int) $m['away_score'] ?></strong>
                            <?php else: ?>
                                Ainda não jogou
                            <?php endif; ?>
                        </span>
                        <?php $pts = ($hasPred && $pred['points'] !== null) ? (int) $pred['points'] : null; ?>
                        <span class="points<?= $pts ? ' scored' : '' ?>" aria-label="<?= $pts === null ? 'sem pontos ainda' : $pts . ' pontos' ?>"><?= $pts === null ? '–' : $pts ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>

            <?php if ($hasOpenMatch): ?>
                <div class="form-foot">
                    <button type="submit" class="full">Salvar palpites</button>
                    <p class="muted-left small-text center">Dá para mudar até o início de cada jogo.</p>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Ranking</h2>
    <div class="table-wrap">
        <table class="tbl">
            <thead>
                <tr><th class="pos">#</th><th>Participante</th><th class="pts">Pontos</th></tr>
            </thead>
            <tbody>
            <?php foreach ($ranking as $i => $row): ?>
                <tr class="<?= (int) $row['id'] === (int) $user['id'] ? 'me' : '' ?>">
                    <td class="pos"><?= $i + 1 ?></td>
                    <td><?= e($row['name']) ?><?= (int) $row['id'] === (int) $user['id'] ? ' <span class="muted-left small-text">(você)</span>' : '' ?></td>
                    <td class="pts"><?= (int) $row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
page_end();
