<?php
// Cabeçalho, rodapé e componentes comuns a todas as páginas.

/** Ícones SVG inline. Herdam a cor do texto e são ocultos para leitores de tela. */
function icon(string $name): string
{
    static $paths = [
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check'      => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'lock'       => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'copy'       => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'share'      => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M8.2 10.8l7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'gear'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'chevron-l'  => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-r'  => '<path d="M9 6l6 6-6 6"/>',
        'eye'        => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'minus'      => '<path d="M5 12h14"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'alert'      => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
    ];

    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** Cabeçalho da página. $section marca o item de menu ativo: inicio, bolao, conta, admin. */
function page_start(string $title, ?array $user = null, string $width = 'wrap', string $section = ''): void
{
    $links = [];
    if ($user) {
        $links[] = ['inicio', 'index.php', 'Meus bolões'];
        $links[] = ['conta', 'perfil.php', 'Conta'];
        if (is_admin($user)) {
            $links[] = ['admin', 'admin_matches.php', 'Administração'];
        }
    }
    $alert = flash_take('alert');
    $notice = flash_take('notice');
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0a1322">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> | <?= e(SITE_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800&family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="topbar">
    <a href="index.php" class="brand"><?= e(SITE_NAME) ?></a>
    <?php if ($links): ?>
        <nav class="nav" aria-label="Principal">
            <?php foreach ($links as [$key, $href, $label]): ?>
                <a class="nav-link" href="<?= e($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</header>
<main id="conteudo" class="<?= e($width) ?>" tabindex="-1">
    <?php if ($alert): ?>
        <div class="alert" role="alert"><p><?= e($alert) ?></p></div>
    <?php endif; ?>
    <?php if ($notice): ?>
        <div class="notice" role="status"><p><?= e($notice) ?></p></div>
    <?php endif; ?>
    <?php
}

function page_end(): void
{
    echo "</main>\n<script src=\"assets/app.js\" defer></script>\n</body>\n</html>\n";
}

/** Página de erro dentro do layout do site (acesso negado, sessão expirada). */
function fail_page(int $code, string $title, string $message, ?array $user = null, array $links = []): void
{
    http_response_code($code);
    page_start($title, $user, 'card', '');
    echo '<section class="panel stack"><h1 class="h-page">' . e($title) . '</h1>';
    echo '<p>' . e($message) . '</p>';
    if ($links) {
        echo '<div class="actions">';
        foreach ($links as [$href, $label]) {
            echo '<a class="btn" href="' . e($href) . '">' . e($label) . '</a>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_end();
    exit;
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
    echo '<div class="notice" role="status"><p>' . e($text) . '</p></div>';
}

/**
 * Campo de texto com rótulo visível, ajuda e erro ligados por atributos (acessível).
 * Senhas ganham botão "Mostrar" (escondido; o JavaScript o exibe).
 *
 * $o: type, value, id, autocomplete, required, maxlength, minlength, min, max,
 *     inputmode, enterkeyhint, help, error, autofocus, placeholder
 */
function field(string $name, string $label, array $o = []): void
{
    $type = $o['type'] ?? 'text';
    $id = $o['id'] ?? ('f-' . $name);
    $help = $o['help'] ?? null;
    $error = $o['error'] ?? null;
    $describedBy = [];
    if ($help) {
        $describedBy[] = $id . '-help';
    }
    if ($error) {
        $describedBy[] = $id . '-error';
    }

    $attrs = ' id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '"';
    $attrs .= ' value="' . e((string) ($o['value'] ?? '')) . '"';
    foreach (['autocomplete', 'maxlength', 'minlength', 'min', 'max', 'inputmode', 'enterkeyhint', 'placeholder'] as $attr) {
        if (isset($o[$attr]) && $o[$attr] !== '') {
            $attrs .= ' ' . $attr . '="' . e((string) $o[$attr]) . '"';
        }
    }
    if (!empty($o['required'])) {
        $attrs .= ' required';
    }
    if (!empty($o['autofocus'])) {
        $attrs .= ' autofocus';
    }
    if ($describedBy) {
        $attrs .= ' aria-describedby="' . e(implode(' ', $describedBy)) . '"';
    }
    if ($error) {
        $attrs .= ' aria-invalid="true"';
    }
    ?>
    <div class="field<?= $error ? ' is-invalid' : '' ?>">
        <label for="<?= e($id) ?>"><?= e($label) ?></label>
        <?php if ($help): ?>
            <p class="help" id="<?= e($id) ?>-help"><?= e($help) ?></p>
        <?php endif; ?>
        <div class="control">
            <input<?= $attrs ?>>
            <?php if ($type === 'password'): ?>
                <button type="button" class="reveal" hidden data-reveal="<?= e($id) ?>" aria-controls="<?= e($id) ?>">Mostrar</button>
            <?php endif; ?>
        </div>
        <?php if ($error): ?>
            <p class="field-error" id="<?= e($id) ?>-error"><?= e($error) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** Caixa de seleção com rótulo ao lado (ligas). */
function check_option(string $name, string $value, string $label, bool $checked, string $id): void
{
    echo '<label class="check" for="' . e($id) . '">'
        . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"'
        . ($checked ? ' checked' : '') . '>'
        . '<span>' . e($label) . '</span></label>';
}
