<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();

$stmt = db()->prepare(
    'SELECT g.id, g.name, g.owner_id, g.round_mode, g.deadline_mode,
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
$uid = (int) $user['id'];

// Para cada bolão: minha posição, palpites pendentes e próximo fechamento.
// Poucas consultas no total, qualquer que seja o número de bolões.
$groupIds = array_map('intval', array_column($groups, 'id'));
$rankings = group_rankings($groupIds);
// Pendentes e próximo prazo seguem a regra de rodada de cada bolão (só contam jogos que já dá para palpitar).
$predictable = group_predictable($groups, $uid, $now);

foreach ($groups as &$group) {
    $gid = (int) $group['id'];
    $position = null;
    foreach (ranking_with_positions($rankings[$gid] ?? []) as $row) {
        if ((int) $row['id'] === $uid) {
            $position = $row;
        }
    }
    $group['pos'] = $position ? $position['pos'] : null;
    $group['points'] = $position ? $position['total'] : 0;
    $group['pending'] = $predictable[$gid]['pending'] ?? 0;
    $group['next'] = $predictable[$gid]['next'] ?? null;
}
unset($group);

page_start('Meus bolões', $user, 'wrap', 'inicio');
?>
<?php
// Resumo do painel: palpites pendentes somados em todos os bolões.
$totalPending = 0;
$groupsPending = 0;
foreach ($groups as $g) {
    $totalPending += (int) $g['pending'];
    if ((int) $g['pending'] > 0) {
        $groupsPending++;
    }
}
$firstName = (string) (preg_split('/\s+/u', trim((string) $user['name']))[0] ?? '');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Olá, <?= e($firstName !== '' ? $firstName : $user['name']) ?></p>
        <h1 class="h-page">Meus bolões</h1>
    </div>
    <?php if ($groups): ?>
        <div class="actions">
            <a class="btn primary" href="create_group.php"><?= icon('plus') ?>Novo bolão</a>
            <a class="btn ghost" href="join_group.php"><?= icon('ticket') ?>Tenho um código</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$groups): ?>
    <section class="empty-hero" aria-labelledby="vazio-titulo">
        <svg class="empty-art" viewBox="0 0 168 104" aria-hidden="true" focusable="false">
            <path class="art-post" d="M20 92V18h128v74" fill="none" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="art-net" d="M30 18v74M42 18v74M54 18v74M66 18v74M78 18v74M90 18v74M102 18v74M114 18v74M126 18v74M138 18v74M20 30h128M20 42h128M20 54h128M20 66h128M20 78h128" fill="none" stroke-width="1.2"/>
            <path class="art-ground" d="M6 94h156" fill="none" stroke-width="3" stroke-linecap="round"/>
            <g transform="translate(96 62)">
                <circle r="16" fill="#fffdf5" stroke="#1a1300" stroke-width="2.2"/>
                <path d="M0-5.5l5.2 3.8-2 6.1h-6.4l-2-6.1z" fill="#1a1300"/>
                <path d="M0-5.5V-12M5.2-1.7l6-2M3.2 4.4l3.8 5M-3.2 4.4l-3.8 5M-5.2-1.7l-6-2" stroke="#1a1300" stroke-width="2" stroke-linecap="round"/>
            </g>
        </svg>
        <div>
            <h2 id="vazio-titulo">Nenhum bolão por aqui ainda</h2>
            <p>Crie o seu e convide a galera, ou entre com o código que um amigo mandou.</p>
        </div>
        <div class="choice-grid">
            <a class="choice" href="create_group.php">
                <?= icon('plus') ?>
                <span><strong>Criar um bolão</strong><span>Você escolhe as ligas e a pontuação.</span></span>
            </a>
            <a class="choice is-alt" href="join_group.php">
                <?= icon('ticket') ?>
                <span><strong>Tenho um código</strong><span>Entre no bolão de um amigo.</span></span>
            </a>
        </div>
    </section>
<?php else: ?>
    <?php if ($totalPending > 0): ?>
        <div class="dash-summary" role="status">
            <?= icon('clock') ?>
            <p><?= e(plural($totalPending, 'palpite pendente', 'palpites pendentes')) ?><?= $groupsPending > 1 ? ' em ' . (int) $groupsPending . ' bolões' : '' ?>. Palpite antes de cada prazo.</p>
        </div>
    <?php else: ?>
        <div class="dash-summary is-ok" role="status">
            <?= icon('check') ?>
            <p>Tudo em dia. Nenhum palpite pendente.</p>
        </div>
    <?php endif; ?>

    <ul class="group-list">
        <?php foreach ($groups as $group):
            $pos = $group['pos'];
            $isOwnerCard = (int) $group['owner_id'] === (int) $user['id'];
        ?>
            <li>
                <a class="group-card<?= $group['pending'] > 0 ? ' has-pending' : '' ?>" href="group.php?id=<?= (int) $group['id'] ?>">
                    <span class="group-card-head">
                        <?= avatar($group['name'], 'avatar-group') ?>
                        <span class="group-card-title">
                            <span class="group-name"><?= e($group['name']) ?></span>
                            <span class="group-facts"><?= e(plural((int) $group['members'], 'participante', 'participantes')) ?><?php if ($isOwnerCard): ?> <span class="tag">organiza</span><?php endif; ?></span>
                        </span>
                        <span class="group-go" aria-hidden="true"><?= icon('chevron-r') ?></span>
                    </span>

                    <span class="group-stats">
                        <span class="stat<?= $pos !== null && (int) $pos === 1 ? ' is-leader' : '' ?>">
                            <span class="stat-label">Posição</span>
                            <span class="stat-value"><?= $pos !== null ? (int) $pos . 'º' : '–' ?><small>de <?= (int) $group['members'] ?></small></span>
                        </span>
                        <span class="stat">
                            <span class="stat-label">Pontos</span>
                            <span class="stat-value"><?= (int) $group['points'] ?></span>
                        </span>
                        <span class="stat">
                            <span class="stat-label">Pendentes</span>
                            <span class="stat-value"><?= (int) $group['pending'] ?></span>
                        </span>
                    </span>

                    <span class="group-status">
                        <?php if ($group['pending'] > 0): ?>
                            <span class="pill is-pending"><?= icon('alert') ?><?= e(plural($group['pending'], 'palpite pendente', 'palpites pendentes')) ?></span>
                        <?php else: ?>
                            <span class="pill is-ok"><?= icon('check') ?>Palpites em dia</span>
                        <?php endif; ?>
                        <?php if ($group['next']): ?>
                            <span class="next-game"><?= icon('calendar') ?>Próximo prazo: <?= e(kick_day_clock((int) $group['next'], $now)) ?></span>
                        <?php else: ?>
                            <span class="next-game"><?= icon('calendar') ?>Nenhum jogo aberto para palpite</span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
page_end();
