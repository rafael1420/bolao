<?php
// Cabeçalho e rodapé comuns a todas as páginas.

function page_start(string $title, ?array $user = null, string $width = 'wrap'): void
{
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#09111d">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> | <?= e(SITE_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800&family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <a href="index.php" class="brand"><?= e(SITE_NAME) ?></a>
    <?php if ($user): ?>
        <nav class="nav" aria-label="Principal">
            <?php $here = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
            <a href="index.php"<?= in_array($here, ['index.php', 'group.php', 'group_settings.php', 'members.php', 'create_group.php', 'join_group.php'], true) ? ' aria-current="page"' : '' ?>>Bolões</a>
            <a href="perfil.php"<?= $here === 'perfil.php' ? ' aria-current="page"' : '' ?>>Conta</a>
            <?php if (is_admin($user)): ?>
                <a href="admin_matches.php"<?= $here === 'admin_matches.php' ? ' aria-current="page"' : '' ?>>Administração</a>
            <?php endif; ?>
        </nav>
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
    echo '<div class="alert" role="alert">';
    foreach ($errors as $error) {
        echo '<p>' . e($error) . '</p>';
    }
    echo '</div>';
}

function notice(string $text): void
{
    echo '<div class="notice" role="status">' . e($text) . '</div>';
}
