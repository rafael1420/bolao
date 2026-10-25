<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$errors = [];
$code = strtolower(trim($_GET['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $code = strtolower(trim($_POST['code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM bolao_groups WHERE invite_code = ?');
    $stmt->execute([$code]);
    $group = $stmt->fetch();

    if (!$group) {
        $errors[] = 'Código de convite inválido.';
    } else {
        $check = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $check->execute([$group['id'], $user['id']]);

        if ($check->fetch()) {
            redirect('group.php?id=' . (int) $group['id']);
        }

        if ($group['password_hash'] !== null && !password_verify($password, $group['password_hash'])) {
            $errors[] = 'Senha do grupo incorreta.';
        }
    }

    if (!$errors) {
        db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$group['id'], $user['id'], 'member']);
        redirect('group.php?id=' . (int) $group['id']);
    }
}

page_start('Entrar em bolão', $user);
?>
<h1>Entrar em bolão</h1>
<p class="muted-left">Use o código ou o link que o organizador enviou. Se o bolão tiver senha, ela será pedida.</p>

<?php alert_box($errors); ?>

<form method="post" action="join_group.php">
    <?= csrf_field() ?>

    <label>Código de convite
        <input type="text" name="code" value="<?= e($code) ?>" maxlength="20" required>
    </label>

    <label>Senha do bolão (se houver)
        <input type="password" name="password">
    </label>

    <button type="submit" class="btn full">Entrar</button>
</form>
<?php
page_end();
