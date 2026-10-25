<?php
require_once __DIR__ . '/includes/app.php';

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
        $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if ($password !== $confirm) {
        $errors[] = 'As senhas não são iguais.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Já existe uma conta com este e-mail. Entre com ele ou use outro.';
        }
    }

    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        login_user((int) db()->lastInsertId());
        redirect('index.php');
    }
}

page_start('Criar conta', null, 'card');
?>
<section class="auth-hero">
    <h1>Criar conta</h1>
    <p class="lead">Leva menos de um minuto. Depois é só criar um bolão ou entrar com o código de um amigo.</p>
</section>

<?php alert_box($errors); ?>

<form method="post" action="register.php" class="panel auth-form">
    <?= csrf_field() ?>

    <label>Nome
        <input type="text" name="name" value="<?= e($old['name']) ?>" maxlength="100" autocomplete="name" required>
    </label>

    <label>E-mail
        <input type="email" name="email" value="<?= e($old['email']) ?>" maxlength="150" autocomplete="email" required>
    </label>

    <label>Senha (mínimo de 8 caracteres)
        <input type="password" name="password" minlength="8" autocomplete="new-password" required>
    </label>

    <label>Confirmar senha
        <input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required>
    </label>

    <button type="submit" class="full">Criar conta</button>
</form>

<p class="muted">Já tem conta? <a href="login.php">Entrar</a></p>
<?php
page_end();
