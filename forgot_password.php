<?php
// "Esqueci minha senha": envia por e-mail um link de redefinição (vale 1 h, uso único).
require_once __DIR__ . '/includes/app.php';

if (current_user()) {
    redirect('perfil.php#bloco-senha');
}

$error = null;
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $error = 'Informe um e-mail válido, como nome@exemplo.com.';
    } else {
        $stmt = db()->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $account = $stmt->fetch() ?: null;
        $ip = client_ip();

        // Resposta igual exista ou não a conta: não revela quem está cadastrado.
        if (!reset_ip_allowed($ip)) {
            $error = 'Muitos pedidos de link a partir desta conexão. Espere uma hora e tente de novo.';
        } else {
            if ($account && reset_user_allowed((int) $account['id'])) {
                $token = create_password_reset((int) $account['id'], 'user');
                if (!send_password_reset_email($account['email'], $account['name'], password_reset_url($token))) {
                    error_log('[bolao] Falha ao enviar o e-mail de redefinição para a conta ' . (int) $account['id']);
                }
            }
            flash_set('forgot_sent', $email);
            redirect('forgot_password.php');
        }
    }
}

$sentTo = flash_take('forgot_sent');

page_start('Esqueci minha senha', null, 'card', '');
?>
<section class="auth-hero">
    <h1 class="h-page">Esqueci minha senha</h1>
    <p class="lead">Informe o e-mail da sua conta. Enviamos um link para você criar uma nova senha.</p>
</section>

<?php if ($sentTo): ?>
    <section class="panel stack" role="status">
        <h2 class="section-title">Confira seu e-mail</h2>
        <p>Se existir uma conta com <strong><?= e($sentTo) ?></strong>, enviamos para ela um link para criar uma nova senha. O link vale por 1 hora e funciona uma vez só.</p>
        <p class="help">Não chegou em alguns minutos? Veja a caixa de spam. Se ainda assim não aparecer, peça à administração do site um link de redefinição.</p>
        <div class="actions"><a class="btn primary" href="login.php">Voltar para entrar</a></div>
    </section>
<?php else: ?>
    <?php alert_box($error ? [$error] : []); ?>

    <form method="post" action="forgot_password.php" class="panel auth-form">
        <?= csrf_field() ?>
        <?php field('email', 'E-mail', ['type' => 'email', 'value' => $email, 'maxlength' => 150, 'autocomplete' => 'email',
            'required' => true, 'autofocus' => true]); ?>
        <button type="submit" class="btn primary wide" data-busy="Enviando…">Enviar link</button>
    </form>

    <p class="muted center">Lembrou? <a href="login.php">Entrar</a></p>
<?php endif; ?>
<?php
page_end();
