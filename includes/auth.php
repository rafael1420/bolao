<?php
require_once __DIR__ . '/db.php';

// Sessão sem atividade por mais tempo que isso termina (e o login também).
const SESSION_IDLE_SECONDS = 7 * 86400;
// Mesmo com uso contínuo, o login vale no máximo isso.
const SESSION_MAX_SECONDS = 30 * 86400;

// Cabeçalhos de segurança, enviados em todas as páginas.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// Sessão segura: cookie só via HTTP e com SameSite.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Expira o login parado há muito tempo ou antigo demais.
    $nowTs = time();
    if (!empty($_SESSION['user_id'])) {
        $lastSeen = (int) ($_SESSION['last_seen'] ?? $nowTs);
        $loginAt = (int) ($_SESSION['login_at'] ?? $nowTs);
        if ($nowTs - $lastSeen > SESSION_IDLE_SECONDS || $nowTs - $loginAt > SESSION_MAX_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['login_at'] = $loginAt;
        }
    }
    $_SESSION['last_seen'] = $nowTs;
    unset($nowTs, $lastSeen, $loginAt);
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
        $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch() ?: null;

        // Senha trocada em outro lugar (ou conta apagada): este login deixa de valer.
        $stamp = $row ? password_stamp($row['password_hash']) : null;
        if ($row && !isset($_SESSION['pw_stamp'])) {
            $_SESSION['pw_stamp'] = $stamp; // sessões anteriores a esta versão
        }
        if (!$row || !hash_equals((string) $_SESSION['pw_stamp'], $stamp)) {
            unset($_SESSION['user_id'], $_SESSION['pw_stamp'], $_SESSION['login_at']);
            return null;
        }
        unset($row['password_hash']);
        $user = $row;
    }

    return $user;
}

/** Marca curta derivada do hash da senha. Muda sempre que a senha muda. */
function password_stamp(string $passwordHash): string
{
    return substr(hash('sha256', $passwordHash), 0, 16);
}

/** Atualiza a marca da senha desta sessão (depois de trocar a própria senha). */
function refresh_password_stamp(string $passwordHash): void
{
    $_SESSION['pw_stamp'] = password_stamp($passwordHash);
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

function login_user(int $userId, string $passwordHash): void
{
    session_regenerate_id(true); // evita fixação de sessão
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
    refresh_password_stamp($passwordHash);
    unset($_SESSION['csrf_token']); // token novo a cada login
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** IP de quem fez a requisição. Não confia em X-Forwarded-For (pode ser forjado). */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Data e hora no formato do banco, no fuso do PHP (o mesmo usado nos jogos). */
function sql_time(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

// Limite de tentativas de login: 5 falhas em 15 min (e-mail + IP) bloqueiam por 15 min.
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW_SECONDS = 15 * 60;
// Por IP, somando todos os e-mails (evita testar senhas em várias contas).
const LOGIN_MAX_FAILS_IP = 30;

/**
 * Quantos segundos faltam para poder tentar de novo. 0 = liberado.
 */
function login_lock_seconds(string $email, string $ip): int
{
    $since = sql_time(time() - LOGIN_WINDOW_SECONDS);

    $stmt = db()->prepare(
        'SELECT attempted_at FROM login_attempts
         WHERE email = ? AND ip = ? AND attempted_at > ?
         ORDER BY attempted_at DESC LIMIT ' . LOGIN_MAX_FAILS
    );
    $stmt->execute([$email, $ip, $since]);
    $rows = $stmt->fetchAll();
    $wait = 0;
    if (count($rows) >= LOGIN_MAX_FAILS) {
        // Bloqueia por 15 min a partir da última falha.
        $wait = strtotime($rows[0]['attempted_at']) + LOGIN_WINDOW_SECONDS - time();
    }

    $stmt = db()->prepare(
        'SELECT attempted_at FROM login_attempts
         WHERE ip = ? AND attempted_at > ?
         ORDER BY attempted_at DESC LIMIT ' . LOGIN_MAX_FAILS_IP
    );
    $stmt->execute([$ip, $since]);
    $rows = $stmt->fetchAll();
    if (count($rows) >= LOGIN_MAX_FAILS_IP) {
        $wait = max($wait, strtotime($rows[0]['attempted_at']) + LOGIN_WINDOW_SECONDS - time());
    }

    return max(0, $wait);
}

function login_record_failure(string $email, string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)')
        ->execute([substr($email, 0, 150), $ip, sql_time(time())]);
    // Limpeza: o que tem mais de um dia não serve para nada.
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([sql_time(time() - 86400)]);
}

function login_clear_failures(string $email, string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute([$email, $ip]);
}

/** "15 minutos", "1 minuto": tempo de espera arredondado para cima. */
function wait_text(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / 60));
    return $minutes . ($minutes === 1 ? ' minuto' : ' minutos');
}
