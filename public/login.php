<?php
require_once __DIR__ . '/../includes/auth.php';

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
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar - <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Entrar</h1>

    <?php if ($error): ?>
        <div class="alert"><p><?= e($error) ?></p></div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <?= csrf_field() ?>

        <label>E-mail
            <input type="email" name="email" value="<?= e($email) ?>" required>
        </label>

        <label>Senha
            <input type="password" name="password" required>
        </label>

        <button type="submit">Entrar</button>
    </form>

    <p class="muted">Ainda não tem conta? <a href="register.php">Criar conta</a></p>
</main>
</body>
</html>
