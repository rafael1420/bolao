<?php
require_once __DIR__ . '/../includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? 0);
$group = require_member($groupId, $user['id']);
$isOwner = (int) $group['owner_id'] === (int) $user['id'];

$leagueIds = group_league_ids($groupId);
$in = placeholders($leagueIds);

// Ligas do grupo, para exibir
$stmt = db()->prepare("SELECT name FROM leagues WHERE id IN ($in) ORDER BY name");
$stmt->execute($leagueIds);
$leagueNames = array_column($stmt->fetchAll(), 'name');

// Rodadas disponíveis
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

// Jogos da rodada
$stmt = db()->prepare(
    "SELECT m.*, l.name AS league_name
     FROM matches m
     JOIN leagues l ON l.id = m.league_id
     WHERE m.league_id IN ($in) AND m.round = ?
     ORDER BY m.kickoff_at, m.id"
);
$stmt->execute([...$leagueIds, $round]);
$matches = $stmt->fetchAll();

// Meus palpites neste grupo
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
    <h1><?= e($group['name']) ?></h1>
    <?php if ($isOwner): ?>
        <a class="btn secondary" href="group_settings.php?id=<?= $groupId ?>">Configurar</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['saved'])): ?>
    <?php notice('Palpites salvos.'); ?>
<?php endif; ?>

<div class="grid-2">
    <section class="panel">
        <h2>Convite</h2>
        <p>Código: <strong><?= e($group['invite_code']) ?></strong></p>
        <p class="small-text">Link: <input type="text" readonly value="<?= e($inviteUrl) ?>" onclick="this.select()"></p>
        <p class="muted-left"><?= $group['password_hash'] ? 'Este bolão tem senha.' : 'Este bolão não tem senha.' ?></p>
    </section>

    <section class="panel">
        <h2>Regras</h2>
        <p><strong>Ligas:</strong> <?= e(implode(', ', $leagueNames)) ?></p>
        <p>Placar exato: <strong><?= (int) $group['exact_points'] ?></strong> ·
           Vencedor ou empate: <strong><?= (int) $group['winner_points'] ?></strong> ·
           Saldo de gols: <strong><?= (int) $group['goal_diff_points'] ?></strong></p>
    </section>
</div>

<section class="panel">
    <div class="round-nav">
        <?php if ($prevRound): ?>
            <a class="btn secondary" href="group.php?id=<?= $groupId ?>&round=<?= $prevRound ?>">&larr; Rodada <?= $prevRound ?></a>
        <?php endif; ?>
        <strong>Rodada <?= $round ?></strong>
        <?php if ($nextRound): ?>
            <a class="btn secondary" href="group.php?id=<?= $groupId ?>&round=<?= $nextRound ?>">Rodada <?= $nextRound ?> &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (!$matches): ?>
        <p class="muted-left">Nenhum jogo cadastrado para esta rodada.</p>
    <?php else: ?>
        <form method="post" action="predict.php">
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= $groupId ?>">
            <input type="hidden" name="round" value="<?= $round ?>">

            <div class="table-wrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Jogo</th>
                            <th>Seu palpite</th>
                            <th>Resultado</th>
                            <th>Pontos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matches as $m):
                        $id = (int) $m['id'];
                        $pred = $mine[$id] ?? null;
                        $open = is_open($m);
                        $finished = $m['home_score'] !== null;
                    ?>
                        <tr>
                            <td><?= e(fmt_kickoff($m['kickoff_at'])) ?></td>
                            <td>
                                <?= e($m['home_team']) ?> x <?= e($m['away_team']) ?>
                                <div class="muted-left small-text"><?= e($m['league_name']) ?></div>
                            </td>
                            <td class="center">
                                <?php if ($open): ?>
                                    <input class="score" type="number" min="0" max="30"
                                           name="preds[<?= $id ?>][home]"
                                           value="<?= $pred ? (int) $pred['home_pred'] : '' ?>">
                                    <span>x</span>
                                    <input class="score" type="number" min="0" max="30"
                                           name="preds[<?= $id ?>][away]"
                                           value="<?= $pred ? (int) $pred['away_pred'] : '' ?>">
                                <?php else: ?>
                                    <?= $pred ? (int) $pred['home_pred'] . ' x ' . (int) $pred['away_pred'] : '-' ?>
                                    <div class="small-text muted-left">Fechado</div>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?= $finished ? (int) $m['home_score'] . ' x ' . (int) $m['away_score'] : 'a definir' ?>
                            </td>
                            <td class="center">
                                <?= ($pred && $pred['points'] !== null) ? (int) $pred['points'] : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($hasOpenMatch): ?>
                <button type="submit" class="btn full">Salvar palpites</button>
                <p class="muted-left small-text">Os palpites podem ser alterados até o início de cada jogo.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Ranking</h2>
    <div class="table-wrap">
        <table class="tbl">
            <thead>
                <tr><th>#</th><th>Participante</th><th>Pontos</th></tr>
            </thead>
            <tbody>
            <?php foreach ($ranking as $i => $row): ?>
                <tr class="<?= (int) $row['id'] === (int) $user['id'] ? 'me' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= (int) $row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
page_end();
