<?php
require_once __DIR__ . '/db.php';

// Sessão segura: cookie só via HTTP e com SameSite.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Escapa texto para HTML. Use sempre que mostrar dados do usuário. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/**
 * Mensagem que sobrevive a um redirecionamento (PRG).
 * Lida uma vez com flash_take(). Some ao recarregar a página.
 */
function flash_set(string $key, $value): void
{
    $_SESSION['flash'][$key] = $value;
}

function flash_take(string $key)
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

/** Token CSRF: impede que outros sites enviem formulários em nome do usuário. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        fail_page(403, 'Sua sessão expirou', 'Entre de novo para salvar seus palpites. Se você digitou algo, copie antes de sair.', null, [['login.php', 'Entrar de novo']]);
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }

    return $user;
}

/** Sem login, manda para a tela de entrada. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

function login_user(int $userId): void
{
    session_regenerate_id(true); // evita fixação de sessão
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}
