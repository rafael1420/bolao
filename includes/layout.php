<?php
// Cabeçalho e rodapé comuns a todas as páginas.

function page_start(string $title, ?array $user = null, string $width = 'card'): void
{
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
    <a href="index.php" class="brand"><?= e(SITE_NAME) ?></a>
    <?php if ($user): ?>
        <form method="post" action="logout.php" class="inline">
            <?= csrf_field() ?>
            <button type="submit" class="link-button">Sair</button>
        </form>
    <?php endif; ?>
</header>
<main class="<?= e($width) ?>">
    <?php
}

function page_end(): void
{
    echo "</main>\n</body>\n</html>\n";
}

function alert_box(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="alert">';
    foreach ($errors as $error) {
        echo '<p>' . e($error) . '</p>';
    }
    echo '</div>';
}

function notice(string $text): void
{
    echo '<div class="notice">' . e($text) . '</div>';
}
