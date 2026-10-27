<?php
require_once __DIR__ . '/includes/app.php';

$next = safe_next($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['next'] ?? null) : ($_GET['next'] ?? null));

if (current_user()) {
    redirect($next ?? 'index.php');
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
        redirect($next ?? 'index.php');
    }

    $error = 'E-mail ou senha incorretos.';
}

$lead = ($next && strpos($next, 'join_group.php') === 0)
    ? 'Entre para abrir o convite do bolão.'
    : 'Palpites rodada a rodada e um ranking só com os seus amigos.';
$registerHref = 'register.php' . ($next ? '?next=' . urlencode($next) : '');

page_start('Entrar', null, 'card', '');
?>
<section class="auth-hero">
    <h1 class="wordmark"><?= e(SITE_NAME) ?></h1>
    <p class="scoreboard" aria-hidden="true"><span>2</span><span class="sep">:</span><span>1</span></p>
    <p class="lead"><?= e($lead) ?></p>
</section>

<?php alert_box($error ? [$error] : []); ?>

<form method="post" action="login.php" class="panel auth-form">
    <?= csrf_field() ?>
    <?php if ($next): ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php endif; ?>

    <?php field('email', 'E-mail', ['type' => 'email', 'value' => $email, 'autocomplete' => 'email', 'required' => true, 'autofocus' => $email === '']); ?>
    <?php field('password', 'Senha', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'autofocus' => $email !== '']); ?>

    <button type="submit" class="btn primary wide">Entrar</button>
</form>

<p class="muted center">Ainda não tem conta? <a href="<?= e($registerHref) ?>">Criar conta</a></p>
<?php
page_end();
