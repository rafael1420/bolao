<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$errors = [];
$message = null;

$stmt = db()->prepare('SELECT id, name, email, password_hash, created_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Informe um nome de até 100 caracteres.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
            $me['name'] = $name;
            $message = 'Nome atualizado.';
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            $errors[] = 'A senha atual está incorreta.';
        }
        if (strlen($new) < 8) {
            $errors[] = 'A nova senha precisa ter pelo menos 8 caracteres.';
        }
        if ($new !== $confirm) {
            $errors[] = 'A confirmação não é igual à nova senha.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            $message = 'Senha alterada.';
        }
    }
}

page_start('Conta', $user, 'wrap');
?>
<div class="page-head">
    <div>
        <h1>Conta</h1>
        <p class="muted-left"><?= e($me['email']) ?></p>
    </div>
</div>

<?php if ($message): ?>
    <?php notice($message); ?>
<?php endif; ?>

<?php alert_box($errors); ?>

<div class="grid-2">
    <section class="panel">
        <h2>Seu nome</h2>
        <p class="muted-left small-text">É como os seus amigos veem você no ranking.</p>
        <form method="post" action="perfil.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <label>Nome
                <input type="text" name="name" value="<?= e($me['name']) ?>" maxlength="100" autocomplete="name" required>
            </label>
            <button type="submit" class="full">Salvar nome</button>
        </form>
    </section>

    <section class="panel">
        <h2>Trocar senha</h2>
        <form method="post" action="perfil.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <label>Senha atual
                <input type="password" name="current" autocomplete="current-password" required>
            </label>
            <label>Nova senha (mínimo de 8 caracteres)
                <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
            </label>
            <label>Confirmar nova senha
                <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </label>
            <button type="submit" class="full">Trocar senha</button>
        </form>
    </section>
</div>
<?php
page_end();
