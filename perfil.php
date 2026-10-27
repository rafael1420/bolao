<?php
require_once __DIR__ . '/includes/app.php';

$user = require_login();
$errors = [];      // campo => mensagem
$old = [];

$stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'O nome precisa ter entre 1 e 100 letras.';
            $old['name'] = $name;
        } else {
            db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
            flash_set('perfil', ['block' => 'nome', 'ok' => 'Nome atualizado.']);
            redirect('perfil.php#bloco-nome');
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            $errors['current'] = 'A senha atual está incorreta.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'A nova senha precisa ter pelo menos 8 caracteres.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'A confirmação não é igual à nova senha.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash_set('perfil', ['block' => 'senha', 'ok' => 'Senha trocada. Use a nova na próxima vez que entrar.']);
            redirect('perfil.php#bloco-senha');
        }
    }
}

$perfilFlash = flash_take('perfil');
$nameValue = $old['name'] ?? $me['name'];
$firstError = $errors ? array_key_first($errors) : null;

page_start('Conta', $user, 'wrap', 'conta');
?>
<div class="page-head">
    <div>
        <h1 class="h-page">Sua conta</h1>
        <p class="lede"><?= e($me['email']) ?> <span class="muted-small">(usado para entrar)</span></p>
    </div>
</div>

<section class="block" id="bloco-nome">
    <h2 class="section-title">Seu nome</h2>
    <p class="help">Seu nome aparece no ranking dos seus bolões.</p>
    <?php if ($perfilFlash && $perfilFlash['block'] === 'nome'): notice($perfilFlash['ok']); endif; ?>
    <form method="post" action="perfil.php#bloco-nome" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <?php field('name', 'Nome', ['value' => $nameValue, 'maxlength' => 100, 'autocomplete' => 'name', 'required' => true,
            'error' => $errors['name'] ?? null, 'autofocus' => $firstError === 'name']); ?>
        <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar nome</button></div>
    </form>
</section>

<section class="block" id="bloco-senha">
    <h2 class="section-title">Trocar senha</h2>
    <?php if ($perfilFlash && $perfilFlash['block'] === 'senha'): notice($perfilFlash['ok']); endif; ?>
    <form method="post" action="perfil.php#bloco-senha" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <?php field('current', 'Senha atual', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true,
            'error' => $errors['current'] ?? null, 'autofocus' => $firstError === 'current']); ?>
        <?php field('new_password', 'Nova senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
            'help' => 'Mínimo de 8 caracteres.', 'error' => $errors['new_password'] ?? null, 'autofocus' => $firstError === 'new_password']); ?>
        <?php field('confirm_password', 'Confirmar nova senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
            'error' => $errors['confirm_password'] ?? null, 'autofocus' => $firstError === 'confirm_password']); ?>
        <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Trocar senha</button></div>
    </form>
</section>

<section class="block">
    <h2 class="section-title">Sair da conta</h2>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn ghost">Sair da conta</button>
    </form>
</section>
<?php
page_end();
