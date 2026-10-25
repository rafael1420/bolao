<?php
require_once __DIR__ . '/includes/app.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Mensagem genérica: não revela se o e-mail existe.
    if ($user && password_verify($password, $user['password_hash'])) {
        login_user((int) $user['id']);
        redirect('index.php');
    }

    $error = 'E-mail ou senha incorretos.';
}

page_start('Entrar', null, 'card');
?>
<section class="auth-hero">
    <h1 class="wordmark"><?= e(SITE_NAME) ?></h1>
    <div class="scoreboard" aria-hidden="true"><span>2</span><span class="sep">:</span><span>1</span></div>
    <p class="lead">Palpites rodada a rodada e um ranking só com os seus amigos.</p>
</section>

<?php alert_box($error ? [$error] : []); ?>

<form method="post" action="login.php" class="panel auth-form">
    <?= csrf_field() ?>

    <label>E-mail
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
    </label>

    <label>Senha
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="full">Entrar</button>
</form>

<p class="muted">Ainda não tem conta? <a href="register.php">Criar conta</a></p>
<?php
page_end();
