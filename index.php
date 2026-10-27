<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();

$stmt = db()->prepare(
    'SELECT g.id, g.name, g.owner_id,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS members
     FROM bolao_groups g
     JOIN group_members m ON m.group_id = g.id
     WHERE m.user_id = ?
     ORDER BY g.name'
);
$stmt->execute([$user['id']]);
$groups = $stmt->fetchAll();

$now = time();
$nowSql = date('Y-m-d H:i:s', $now);

// Para cada bolão: minha posição, palpites pendentes e próximo fechamento.
foreach ($groups as &$group) {
    $gid = (int) $group['id'];
    $leagueIds = group_league_ids($gid);
    $in = placeholders($leagueIds);

    $position = null;
    $ranking = ranking_with_positions(group_ranking($gid));
    foreach ($ranking as $row) {
        if ((int) $row['id'] === (int) $user['id']) {
            $position = $row;
        }
    }
    $group['pos'] = $position ? $position['pos'] : null;
    $group['points'] = $position ? $position['total'] : 0;

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM matches m
         WHERE m.league_id IN ($in) AND m.kickoff_at > ? AND m.home_score IS NULL
           AND NOT EXISTS (SELECT 1 FROM predictions p
                           WHERE p.match_id = m.id AND p.user_id = ? AND p.group_id = ?)"
    );
    $stmt->execute([...$leagueIds, $nowSql, $user['id'], $gid]);
    $group['pending'] = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT MIN(m.kickoff_at) FROM matches m
         WHERE m.league_id IN ($in) AND m.kickoff_at > ? AND m.home_score IS NULL"
    );
    $stmt->execute([...$leagueIds, $nowSql]);
    $next = $stmt->fetchColumn();
    $group['next'] = $next ? strtotime($next) : null;
}
unset($group);

page_start('Meus bolões', $user, 'wrap', 'inicio');
?>
<div class="page-head">
    <div>
        <h1 class="h-page">Meus bolões</h1>
        <p class="lede">Olá, <?= e($user['name']) ?>.</p>
    </div>
    <div class="actions">
        <a class="btn primary" href="create_group.php">Novo bolão</a>
        <a class="btn ghost" href="join_group.php">Tenho um código</a>
    </div>
</div>

<?php if (!$groups): ?>
    <section class="panel empty-state">
        <h2>Nenhum bolão por aqui ainda.</h2>
        <p>Crie o seu e convide a galera, ou peça o código para um amigo.</p>
        <div class="actions">
            <a class="btn primary" href="create_group.php">Novo bolão</a>
            <a class="btn ghost" href="join_group.php">Tenho um código</a>
        </div>
    </section>
<?php else: ?>
    <ul class="group-list">
        <?php foreach ($groups as $group): ?>
            <li>
                <a class="group-card" href="group.php?id=<?= (int) $group['id'] ?>">
                    <span class="group-name"><?= e($group['name']) ?></span>
                    <span class="group-facts"><?= e(plural((int) $group['members'], 'participante', 'participantes')) ?><?php if ($group['pos'] !== null): ?>, você em <?= (int) $group['pos'] ?>º com <?= (int) $group['points'] ?> pontos<?php endif; ?></span>
                    <span class="group-facts">
                        <?php if ($group['pending'] > 0): ?>
                            <span class="pending"><?= e(plural($group['pending'], 'palpite pendente', 'palpites pendentes')) ?></span>.
                        <?php else: ?>
                            Palpites em dia.
                        <?php endif; ?>
                        <?php if ($group['next']): ?>
                            Próximo jogo: <?= e(kick_day_clock((int) $group['next'], $now)) ?>.
                        <?php endif; ?>
                    </span>
                    <?php if ((int) $group['owner_id'] === (int) $user['id']): ?>
                        <span class="tag">organiza</span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
page_end();
