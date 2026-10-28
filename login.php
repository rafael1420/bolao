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
    $password = (string) ($_POST['password'] ?? '');
    $ip       = client_ip();

    // Muitas falhas seguidas: nem confere a senha.
    $wait = login_lock_seconds($email, $ip);
    if ($wait > 0) {
        http_response_code(429);
        $error = 'Muitas tentativas sem acertar a senha. Por segurança, espere ' . wait_text($wait)
            . ' e tente de novo, ou use "Esqueci minha senha".';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Mensagem genérica: não revela se o e-mail existe.
        if ($user && password_verify($password, $user['password_hash'])) {
            $hash = $user['password_hash'];
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
            }
            login_clear_failures($email, $ip);
            login_user((int) $user['id'], $hash);
            redirect($next ?? 'index.php');
        }

        login_record_failure($email, $ip);
        $wait = login_lock_seconds($email, $ip);
        $error = $wait > 0
            ? 'Muitas tentativas sem acertar a senha. Por segurança, espere ' . wait_text($wait)
                . ' e tente de novo, ou use "Esqueci minha senha".'
            : 'E-mail ou senha incorretos.';
    }
}

$lead = ($next && strpos($next, 'join_group.php') === 0)
    ? 'Entre para abrir o convite do bolão.'
    : 'Palpites rodada a rodada e um ranking só com os seus amigos.';
$registerHref = 'register.php' . ($next ? '?next=' . urlencode($next) : '');

page_start('Entrar', null, 'auth', '');
?>
<div class="auth-grid">
    <?php auth_pitch(); ?>

    <section class="auth-panel" aria-labelledby="titulo-entrar">
        <h1 class="h-page" id="titulo-entrar">Entrar</h1>
        <p class="lead"><?= e($lead) ?></p>

        <?php alert_box($error ? [$error] : []); ?>

        <form method="post" action="login.php" class="panel auth-form">
            <?= csrf_field() ?>
            <?php if ($next): ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
            <?php endif; ?>

            <?php field('email', 'E-mail', ['type' => 'email', 'value' => $email, 'autocomplete' => 'email', 'required' => true, 'autofocus' => $email === '']); ?>
            <?php field('password', 'Senha', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'autofocus' => $email !== '']); ?>
            <p class="form-links"><a href="forgot_password.php">Esqueci minha senha</a></p>

            <button type="submit" class="btn primary wide" data-busy="Entrando…">Entrar</button>
        </form>

        <p class="muted center auth-switch">Ainda não tem conta? <a href="<?= e($registerHref) ?>">Criar conta</a></p>
    </section>
</div>
<?php
page_end();
