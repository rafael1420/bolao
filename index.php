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

page_start('Seus bolões', $user, 'wrap');
?>
<div class="page-head">
    <div>
        <h1>Seus bolões</h1>
        <p class="muted-left">Olá, <?= e($user['name']) ?>.</p>
    </div>
    <div class="actions">
        <a class="btn" href="create_group.php">Criar bolão</a>
        <a class="btn secondary" href="join_group.php">Entrar com código</a>
    </div>
</div>

<?php if (!$groups): ?>
    <div class="empty">
        <p>Você ainda não está em nenhum bolão.</p>
        <p class="muted-left">Crie um para começar, ou peça o código de convite para um amigo.</p>
    </div>
<?php else: ?>
    <div class="group-list">
        <?php foreach ($groups as $group): ?>
            <a class="group-card" href="group.php?id=<?= (int) $group['id'] ?>">
                <span class="group-name"><?= e($group['name']) ?></span>
                <span class="muted-left">
                    <?= (int) $group['members'] ?> <?= (int) $group['members'] === 1 ? 'participante' : 'participantes' ?>
                    <?php if ((int) $group['owner_id'] === (int) $user['id']): ?>
                        <br>Você organiza este bolão
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
page_end();
