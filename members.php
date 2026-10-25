<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
$group = require_member($groupId, $user['id']);

// Só o organizador gerencia os membros.
if ((int) $group['owner_id'] !== (int) $user['id']) {
    redirect('group.php?id=' . $groupId);
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') === 'remove') {
        $removeId = (int) ($_POST['user_id'] ?? 0);
        // O organizador nunca é removido.
        if ($removeId !== (int) $group['owner_id']) {
            db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
                ->execute([$groupId, $removeId]);
            $message = 'Participante removido.';
        }
    }
}

$stmt = db()->prepare(
    'SELECT u.id, u.name, u.email, gm.role
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = ?
     ORDER BY gm.role = "owner" DESC, u.name'
);
$stmt->execute([$groupId]);
$members = $stmt->fetchAll();

page_start('Membros', $user, 'wrap');
?>
<h1>Membros de <?= e($group['name']) ?></h1>
<p class="muted-left">Ao remover alguém, a pessoa deixa o bolão. Os palpites dela continuam guardados, e voltam se ela entrar de novo.</p>

<?php if ($message): ?><?php notice($message); ?><?php endif; ?>

<section class="panel">
    <div class="table-wrap">
        <table class="tbl">
            <thead>
                <tr><th>Nome</th><th>E-mail</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td><?= e($member['name']) ?><?= $member['role'] === 'owner' ? ' <span class="muted-left small-text">(organizador)</span>' : '' ?></td>
                    <td><?= e($member['email']) ?></td>
                    <td>
                        <?php if ($member['role'] !== 'owner'): ?>
                            <form method="post" action="members.php" class="inline-form"
                                  onsubmit="return confirm('Remover este participante?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                <button type="submit" class="btn small secondary">Remover</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<p><a href="group_settings.php?id=<?= $groupId ?>">&larr; Configurações</a> · <a href="group.php?id=<?= $groupId ?>">Voltar ao bolão</a></p>
<?php
page_end();
