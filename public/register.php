<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name     = trim($_POST['name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';
    $old      = ['name' => $name, 'email' => $email];

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Informe um nome de até 100 caracteres.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
    }
    if ($password !== $confirm) {
        $errors[] = 'As senhas não conferem.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }
    }

    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        login_user((int) db()->lastInsertId());
        redirect('index.php');
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar conta - <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Criar conta</h1>

    <?php if ($errors): ?>
        <div class="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php">
        <?= csrf_field() ?>

        <label>Nome
            <input type="text" name="name" value="<?= e($old['name']) ?>" maxlength="100" required>
        </label>

        <label>E-mail
            <input type="email" name="email" value="<?= e($old['email']) ?>" maxlength="150" required>
        </label>

        <label>Senha (mínimo 8 caracteres)
            <input type="password" name="password" minlength="8" required>
        </label>

        <label>Confirmar senha
            <input type="password" name="password_confirm" minlength="8" required>
        </label>

        <button type="submit">Criar conta</button>
    </form>

    <p class="muted">Já tem conta? <a href="login.php">Entrar</a></p>
</main>
</body>
</html>
