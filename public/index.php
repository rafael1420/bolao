<?php
require_once __DIR__ . '/../includes/app.php';

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

page_start('Início', $user, 'wrap');
?>
<h1>Olá, <?= e($user['name']) ?>!</h1>

<div class="actions">
    <a class="btn" href="create_group.php">+ Criar bolão</a>
    <a class="btn secondary" href="join_group.php">Entrar com código</a>
</div>

<h2>Meus bolões</h2>

<?php if (!$groups): ?>
    <p class="muted-left">Você ainda não participa de nenhum bolão. Crie um ou entre com um código de convite.</p>
<?php endif; ?>

<div class="group-list">
    <?php foreach ($groups as $group): ?>
        <a class="panel group-card" href="group.php?id=<?= (int) $group['id'] ?>">
            <strong><?= e($group['name']) ?></strong>
            <span class="muted-left"><?= (int) $group['members'] ?> membro(s)<?= (int) $group['owner_id'] === (int) $user['id'] ? ' · você é o organizador' : '' ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php
page_end();
