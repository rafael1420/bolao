<?php
// Cabeçalho, rodapé e componentes comuns a todas as páginas.

/** Caminho de um arquivo em assets/ com a data de modificação, para o navegador não usar a versão antiga do cache. */
function asset(string $file): string
{
    $mtime = @filemtime(__DIR__ . '/../assets/' . $file);
    return 'assets/' . $file . ($mtime ? '?v=' . $mtime : '');
}

/** Ícones SVG inline. Herdam a cor do texto e são ocultos para leitores de tela. */
function icon(string $name): string
{
    static $paths = [
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check'       => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'lock'        => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'copy'        => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'share'       => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M8.2 10.8l7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'gear'        => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'arrow-left'  => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron-l'   => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-r'   => '<path d="M9 6l6 6-6 6"/>',
        'eye'         => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'minus'       => '<path d="M5 12h14"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'alert'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
        'home'        => '<path d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"/>',
        'plus-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'ticket'      => '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2.5a2.5 2.5 0 0 0 0 5V17a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2.5a2.5 2.5 0 0 0 0-5z"/><path d="M14.5 5.5v2M14.5 11v2M14.5 16.5v2"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'shield'      => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M2.5 12h2M19.5 12h2M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'moon'        => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'trophy'      => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 20.5h7M10 17h4"/>',
        'calendar'    => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
        'logout'      => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/>',
        'star'        => '<path d="M12 3.5l2.6 5.3 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.7 1-5.8-4.3-4.1 5.9-.8z"/>',
        'list'        => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'x'           => '<path d="M6 6l12 12M18 6L6 18"/>',
        'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/>',
        'whistle'     => '<circle cx="9" cy="14" r="5"/><path d="M12.5 10.5L21 6v4l-6 2.5M9 14h.01"/>',
    ];

    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** Símbolo da marca: um placar com uma bola. Usado no topo, na entrada e no favicon. */
function brand_mark(string $class = 'brand-mark'): string
{
    static $count = 0;
    $clip = 'bm-' . (++$count);
    return '<svg class="' . e($class) . '" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true" focusable="false">'
        . '<defs><clipPath id="' . $clip . '"><circle cx="16" cy="16" r="9.4"/></clipPath></defs>'
        . '<rect width="32" height="32" rx="9" fill="#ffc832"/>'
        . '<circle cx="16" cy="16" r="9.4" fill="#fffdf5"/>'
        . '<g clip-path="url(#' . $clip . ')" fill="#1a1300">'
        . '<path d="M16 11.7L20.09 14.67L18.53 19.48L13.47 19.48L11.91 14.67zM16 9L11.34 5.61L13.12 0.14L18.88 0.14L20.66 5.61zM22.66 13.84L24.44 8.36L30.2 8.36L31.98 13.84L27.32 17.22zM20.11 21.66L25.87 21.66L27.65 27.14L22.99 30.53L18.33 27.14zM11.89 21.66L13.67 27.14L9.01 30.53L4.35 27.14L6.13 21.66zM9.34 13.84L4.68 17.22L0.02 13.84L1.8 8.36L7.56 8.36z"/>'
        . '<path d="M16 11.7V9M20.09 14.67l2.57-.83M18.53 19.48l1.58 2.18M13.47 19.48l-1.58 2.18M11.91 14.67l-2.57-.83" stroke="#1a1300" stroke-width="1.4" stroke-linecap="round"/>'
        . '</g><circle cx="16" cy="16" r="9.4" fill="none" stroke="#1a1300" stroke-width="1.8"/>'
        . '</svg>';
}

/** Número de 0 a 359 sempre igual para o mesmo texto (cor de escudos e avatares). */
function name_hue(string $text): int
{
    $key = function_exists('mb_strtolower') ? mb_strtolower(trim($text), 'UTF-8') : strtolower(trim($text));
    return (int) (sprintf('%u', crc32($key)) % 360);
}

/** Iniciais de uma pessoa ou bolão: "Rafael Gomes" vira "RG", "Bolão" vira "BO". */
function initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) {
        return '?';
    }
    if (count($words) === 1) {
        return mb_strtoupper(mb_substr($words[0], 0, 2, 'UTF-8'), 'UTF-8');
    }
    $first = mb_substr($words[0], 0, 1, 'UTF-8');
    $last = mb_substr($words[count($words) - 1], 0, 1, 'UTF-8');
    return mb_strtoupper($first . $last, 'UTF-8');
}

/**
 * Avatar redondo com as iniciais e uma cor fixa pelo nome. Decorativo: o nome
 * sempre aparece em texto ao lado. $class: avatar-sm, avatar-lg, avatar-group.
 */
function avatar(string $name, string $class = ''): string
{
    return '<span class="avatar' . ($class !== '' ? ' ' . e($class) : '') . '" style="--h: ' . name_hue($name) . '" aria-hidden="true">'
        . e(initials($name)) . '</span>';
}

/** Sigla do time no escudo: "Flamengo" vira "FLA", "São Paulo" vira "SP", "Red Bull Bragantino" vira "RBB". */
function team_initials(string $team): string
{
    $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $team);
    $words = preg_split('/\s+/u', trim((string) $clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $skip = ['de', 'da', 'do', 'das', 'dos', 'e', 'fc', 'ec', 'sc', 'ac', 'cf', 'cr', 'club', 'clube', 'esporte', 'sport'];
    $main = array_values(array_filter($words, function ($w) use ($skip) {
        return !in_array(mb_strtolower($w, 'UTF-8'), $skip, true);
    }));
    if (!$main) {
        $main = $words;
    }
    if (!$main) {
        return '?';
    }
    if (count($main) === 1) {
        return mb_strtoupper(mb_substr($main[0], 0, 3, 'UTF-8'), 'UTF-8');
    }
    $out = '';
    foreach (array_slice($main, 0, 3) as $w) {
        $out .= mb_substr($w, 0, 1, 'UTF-8');
    }
    return mb_strtoupper($out, 'UTF-8');
}

/**
 * Escudo SVG do time: formato de escudo, cor e desenho (liso, meio a meio, faixa,
 * listras ou faixa diagonal) escolhidos a partir do nome, e a sigla no centro.
 * O mesmo nome gera sempre o mesmo escudo. Decorativo: o nome do time vem ao lado.
 * $size: '' (médio), 'sm' ou 'lg'.
 */
function team_crest(string $team, string $size = ''): string
{
    static $count = 0;
    $count++;
    $clip = 'crest-' . $count;

    $hash = (int) sprintf('%u', crc32(mb_strtolower(trim($team), 'UTF-8')));
    $hue = $hash % 360;
    $sat = 52 + ($hash >> 9) % 20;
    $main = 'hsl(' . $hue . ', ' . $sat . '%, 36%)';
    $second = (($hash >> 12) % 2) ? '#f1f4f9' : 'hsl(' . $hue . ', ' . $sat . '%, 16%)';
    $pattern = ($hash >> 14) % 5;

    $shield = 'M20 1.5L37.5 6.8V21c0 11.8-7.6 19.6-17.5 23.5C10.1 40.6 2.5 32.8 2.5 21V6.8z';
    $shapes = [
        0 => '',
        1 => '<rect x="20" y="0" width="20" height="46" fill="' . $second . '"/>',
        2 => '<rect x="0" y="16.5" width="40" height="11" fill="' . $second . '"/>',
        3 => '<rect x="9" y="0" width="5.5" height="46" fill="' . $second . '"/><rect x="25.5" y="0" width="5.5" height="46" fill="' . $second . '"/>',
        4 => '<path d="M-2 10L10 -2L44 32L32 44z" fill="' . $second . '"/>',
    ];

    $letters = team_initials($team);
    $fontSize = mb_strlen($letters, 'UTF-8') >= 3 ? 13 : 15.5;

    return '<svg class="crest' . ($size !== '' ? ' crest-' . e($size) : '') . '" viewBox="0 0 40 46" width="40" height="46" aria-hidden="true" focusable="false">'
        . '<defs><clipPath id="' . $clip . '"><path d="' . $shield . '"/></clipPath></defs>'
        . '<g clip-path="url(#' . $clip . ')"><rect width="40" height="46" fill="' . $main . '"/>' . $shapes[$pattern] . '</g>'
        . '<path d="' . $shield . '" fill="none" stroke="rgba(255,255,255,.28)" stroke-width="1.5"/>'
        . '<path d="' . $shield . '" fill="none" stroke="rgba(0,0,0,.22)" stroke-width=".8"/>'
        . '<text x="20" y="24.5" text-anchor="middle" dominant-baseline="middle" font-family="\'Big Shoulders Display\', \'Arial Narrow\', sans-serif" '
        . 'font-weight="800" font-size="' . $fontSize . '" letter-spacing=".4" fill="#fff" stroke="rgba(0,0,0,.4)" stroke-width="1.6" '
        . 'paint-order="stroke" stroke-linejoin="round">' . e($letters) . '</text>'
        . '</svg>';
}

/**
 * Apresentação do bolão nas telas de entrada e cadastro: marca, chamada, três motivos e
 * um placar de exemplo. No celular fica curta, acima do formulário.
 */
function auth_pitch(): void
{
    ?>
    <section class="auth-pitch" aria-label="Sobre o <?= e(SITE_NAME) ?>">
        <div class="auth-brand"><?= brand_mark() ?><span class="wordmark"><?= e(SITE_NAME) ?></span></div>
        <p class="auth-title">O bolão da <em>sua turma</em>, rodada a rodada.</p>
        <p class="lead">Palpite nos jogos, acompanhe o ranking e tire onda com os amigos. Sem dinheiro, só resenha.</p>
        <ul class="auth-features">
            <li><?= icon('clock') ?><span><strong>Palpite até o apito inicial</strong>Dá para mudar o placar até cada jogo começar.</span></li>
            <li><?= icon('trophy') ?><span><strong>Ranking automático</strong>Placar exato, vencedor e saldo de gols viram pontos sozinhos.</span></li>
            <li><?= icon('users') ?><span><strong>Só com quem você chamar</strong>Convite por link ou código, com senha se quiser.</span></li>
        </ul>
        <div class="auth-demo" aria-hidden="true">
            <span class="demo-team"><?= team_crest('Galera Futebol Clube') ?>Galera</span>
            <span class="scoreboard"><span>2</span><span class="sep">:</span><span>1</span></span>
            <span class="demo-team"><?= team_crest('Amigos') ?>Amigos</span>
        </div>
    </section>
    <?php
}

/** Cabeçalho da página. $section marca o item de menu ativo: inicio, bolao, conta, admin. */
function page_start(string $title, ?array $user = null, string $width = 'wrap', string $section = ''): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    // Menu do topo (telas largas)
    $links = [];
    // Barra inferior (celular): os mesmos destinos, com ícones e atalhos para criar e entrar
    $tabbar = [];
    if ($user) {
        $links[] = ['inicio', 'index.php', 'Meus bolões'];
        $links[] = ['conta', 'perfil.php', 'Conta'];

        $current = $section;
        if ($script === 'create_group.php') {
            $current = 'novo';
        } elseif ($script === 'join_group.php') {
            $current = 'codigo';
        } elseif ($section === 'bolao') {
            $current = 'inicio';
        }
        $tabbar[] = ['inicio', 'index.php', 'Bolões', 'home', $current];
        $tabbar[] = ['novo', 'create_group.php', 'Novo', 'plus-circle', $current];
        $tabbar[] = ['codigo', 'join_group.php', 'Código', 'ticket', $current];
        $tabbar[] = ['conta', 'perfil.php', 'Conta', 'user', $current];
        if (is_admin($user)) {
            $links[] = ['admin', 'admin_matches.php', 'Administração'];
            $tabbar[] = ['admin', 'admin_matches.php', 'Admin', 'shield', $current];
        }
    }
    $alert = flash_take('alert');
    $notice = flash_take('notice');
    $bodyClass = $tabbar ? 'has-tabbar' : 'no-tabbar';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f3f5fa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a1120" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex">
    <meta name="apple-mobile-web-app-title" content="<?= e(SITE_NAME) ?>">
    <title><?= e($title) ?> | <?= e(SITE_NAME) ?></title>
    <script>
        /* Tema escolhido antes de desenhar a página, para não piscar. */
        (function () {
            try {
                var t = localStorage.getItem('bolao-tema');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                    var metas = document.querySelectorAll('meta[name="theme-color"]');
                    for (var i = 0; i < metas.length; i++) {
                        metas[i].setAttribute('content', t === 'light' ? '#f3f5fa' : '#0a1120');
                    }
                }
            } catch (e) {}
        })();
    </script>
    <link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(asset('icon-192.png')) ?>" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= e(asset('apple-touch-icon.png')) ?>">
    <link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="brand"><?= brand_mark() ?><span class="brand-name"><?= e(SITE_NAME) ?></span></a>
        <?php if ($links): ?>
            <nav class="nav" aria-label="Principal">
                <?php foreach ($links as [$key, $href, $label]): ?>
                    <a class="nav-link" href="<?= e($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="topbar-tools">
            <button type="button" class="icon-btn theme-toggle" data-theme-toggle hidden aria-label="Mudar o tema">
                <span class="theme-icon theme-icon-sun"><?= icon('sun') ?></span>
                <span class="theme-icon theme-icon-moon"><?= icon('moon') ?></span>
            </button>
            <?php if ($user): ?>
                <a class="me-link" href="perfil.php"<?= $section === 'conta' ? ' aria-current="page"' : '' ?>>
                    <?= avatar((string) $user['name'], 'avatar-sm') ?>
                    <span class="visually-hidden">Sua conta, <?= e($user['name']) ?></span>
                </a>
            <?php elseif ($script !== 'login.php' && $script !== 'install.php'): ?>
                <a class="btn ghost small" href="login.php">Entrar</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php if ($tabbar): ?>
<nav class="tabbar" aria-label="Atalhos">
    <?php foreach ($tabbar as [$key, $href, $label, $ico, $current]): ?>
        <a class="tabbar-link" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
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
    $year = date('Y');
    echo "</main>\n"
        . '<footer class="site-foot"><p>' . e(SITE_NAME) . ' · bolão entre amigos, sem dinheiro envolvido · ' . $year . "</p></footer>\n"
        . "<script src=\"" . e(asset('app.js')) . "\" defer></script>\n</body>\n</html>\n";
}

/** Página de erro dentro do layout do site (acesso negado, sessão expirada). */
function fail_page(int $code, string $title, string $message, ?array $user = null, array $links = []): void
{
    http_response_code($code);
    page_start($title, $user, 'card', '');
    echo '<section class="panel stack fail-panel"><span class="fail-icon">' . icon('whistle') . '</span>';
    echo '<h1 class="h-page">' . e($title) . '</h1>';
    echo '<p>' . e($message) . '</p>';
    if ($links) {
        echo '<div class="actions">';
        foreach ($links as [$href, $label]) {
            echo '<a class="btn primary" href="' . e($href) . '">' . e($label) . '</a>';
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

/**
 * Caixa de seleção com rótulo ao lado (ligas). $extraHtml (já escapado) vai depois do rótulo:
 * escudo e etiquetas no seletor de ligas. $attrs: atributos extras do <label> (já escapados).
 */
function check_option(string $name, string $value, string $label, bool $checked, string $id, string $extraHtml = '', string $attrs = ''): void
{
    echo '<label class="check' . ($extraHtml !== '' ? ' check-card' : '') . '" for="' . e($id) . '"' . $attrs . '>'
        . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"'
        . ($checked ? ' checked' : '') . '>'
        . '<span class="check-label">' . e($label) . '</span>' . $extraHtml . '</label>';
}

/**
 * Endereço de uma imagem da ESPN já reduzida pelo próprio CDN (64 px pesa ~6 KB; a original, ~60 KB).
 * Só aceita imagens de a.espncdn.com; qualquer outra coisa devolve null (fica o escudo desenhado).
 */
function espn_img(?string $url, int $size): ?string
{
    if ($url === null || !preg_match('#^https://a\.espncdn\.com(/i/[A-Za-z0-9/_.-]+\.png)$#', $url, $m)) {
        return null;
    }
    return 'https://a.espncdn.com/combiner/i?img=' . $m[1] . '&w=' . $size . '&h=' . $size;
}
