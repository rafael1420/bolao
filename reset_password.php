<?php
// Tela do link de redefinição: confere o token e grava a nova senha.
require_once __DIR__ . '/includes/app.php';

// O token não pode vazar para outros sites pelo cabeçalho Referer.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = strtolower(trim((string) ($isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''))));
$errors = [];   // campo => mensagem

if ($isPost) {
    verify_csrf();
}

$reset = find_password_reset($token);

if ($isPost && $reset) {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $errors['password'] = 'A senha precisa ter pelo menos 8 caracteres.';
    } elseif (strlen($password) > 200) {
        $errors['password'] = 'A senha pode ter no máximo 200 caracteres.';
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = 'As senhas não são iguais.';
    }

    if (!$errors) {
        $userId = consume_password_reset($token, $password);
        if ($userId !== null) {
            // Libera o login que estava bloqueado por tentativas erradas.
            db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$reset['email']]);
            // Quem estava logado neste navegador sai; as outras sessões caem pela troca da senha.
            if (current_user()) {
                logout_user();
                session_start();
            }
            flash_set('notice', 'Senha redefinida. Entre com a nova senha.');
            redirect('login.php');
        }
        $reset = null; // venceu ou foi usado entre a abertura da tela e o envio
    }
}

page_start('Nova senha', null, 'card', '');

if (!$reset):
    ?>
    <section class="panel stack">
        <h1 class="h-page">Link inválido ou vencido</h1>
        <p>Este link de redefinição não funciona mais. Ele vale por pouco tempo e só pode ser usado uma vez.</p>
        <div class="actions">
            <a class="btn primary" href="forgot_password.php">Pedir um novo link</a>
            <a class="btn ghost" href="login.php">Entrar</a>
        </div>
    </section>
    <?php
else:
    $firstError = $errors ? array_key_first($errors) : null;
    ?>
    <section class="auth-hero">
        <h1 class="h-page">Criar nova senha</h1>
        <p class="lead">Conta de <?= e($reset['email']) ?>.</p>
    </section>

    <?php alert_box(array_values($errors)); ?>

    <form method="post" action="reset_password.php" class="panel auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="email" name="username" value="<?= e($reset['email']) ?>" autocomplete="username" hidden>
        <?php field('password', 'Nova senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
            'help' => 'Mínimo de 8 caracteres.', 'error' => $errors['password'] ?? null, 'autofocus' => $firstError !== 'password_confirm']); ?>
        <?php field('password_confirm', 'Confirmar nova senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
            'error' => $errors['password_confirm'] ?? null, 'autofocus' => $firstError === 'password_confirm']); ?>
        <button type="submit" class="btn primary wide" data-busy="Salvando…">Salvar nova senha</button>
    </form>
    <?php
endif;

page_end();
