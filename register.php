<?php
require_once __DIR__ . '/includes/app.php';

$next = safe_next($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['next'] ?? null) : ($_GET['next'] ?? null));

if (current_user()) {
    redirect($next ?? 'index.php');
}

$errors = [];   // campo => mensagem
$summary = [];  // lista do topo
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name     = trim($_POST['name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';
    $old      = ['name' => $name, 'email' => $email];

    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'O nome precisa ter entre 1 e 100 letras.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors['email'] = 'Informe um e-mail válido, como nome@exemplo.com.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (!isset($errors['password']) && $password !== $confirm) {
        $errors['password_confirm'] = 'As senhas não são iguais.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Já existe uma conta com este e-mail. Entre com ele ou use outro.';
        }
    }

    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        login_user((int) db()->lastInsertId());
        redirect($next ?? 'index.php');
    }
    $summary = array_values($errors);
}

$firstError = $errors ? array_key_first($errors) : null;
$loginHref = 'login.php' . ($next ? '?next=' . urlencode($next) : '');

page_start('Criar conta', null, 'card', '');
?>
<section class="auth-hero">
    <h1 class="h-page">Criar conta</h1>
    <p class="lead">Leva menos de um minuto. Depois é só criar um bolão ou entrar com o código de um amigo.</p>
</section>

<?php alert_box($summary); ?>

<form method="post" action="register.php<?= $next ? '?next=' . e(urlencode($next)) : '' ?>" class="panel auth-form">
    <?= csrf_field() ?>
    <?php if ($next): ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php endif; ?>

    <?php field('name', 'Nome', ['value' => $old['name'], 'maxlength' => 100, 'autocomplete' => 'name', 'required' => true,
        'help' => 'É como os seus amigos veem você no ranking.', 'error' => $errors['name'] ?? null,
        'autofocus' => $firstError === 'name']); ?>
    <?php field('email', 'E-mail', ['type' => 'email', 'value' => $old['email'], 'maxlength' => 150, 'autocomplete' => 'email', 'required' => true,
        'error' => $errors['email'] ?? null, 'autofocus' => $firstError === 'email']); ?>
    <?php field('password', 'Senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
        'help' => 'Mínimo de 8 caracteres.', 'error' => $errors['password'] ?? null, 'autofocus' => $firstError === 'password']); ?>
    <?php field('password_confirm', 'Confirmar senha', ['type' => 'password', 'minlength' => 8, 'autocomplete' => 'new-password', 'required' => true,
        'error' => $errors['password_confirm'] ?? null, 'autofocus' => $firstError === 'password_confirm']); ?>

    <button type="submit" class="btn primary wide">Criar conta</button>
</form>

<p class="muted center">Já tem conta? <a href="<?= e($loginHref) ?>">Entrar</a></p>
<?php
page_end();
