<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <strong><?= e(SITE_NAME) ?></strong>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="link-button">Sair</button>
    </form>
</header>

<main class="card">
    <h1>Olá, <?= e($user['name']) ?>!</h1>
    <p class="muted">Em breve: seus bolões, criação de grupos e palpites.</p>
</main>
</body>
</html>
