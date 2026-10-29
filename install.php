<?php
// Instalador do bolão. Rode uma vez: ele cria o banco, o config.php e se apaga ao final.
declare(strict_types=1);

$root = __DIR__;
$configFile = $root . '/includes/config.php';
$schemaFile = $root . '/sql/schema.sql';

if (file_exists($configFile)) {
    http_response_code(403);
    exit('O bolão já está instalado. Apague o arquivo install.php do servidor.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errors = [];
$done = false;
$values = [
    'site_name' => 'Conferência Bolão',
    'host' => 'localhost',
    'name' => '',
    'user' => '',
    'admin_email' => '',
    'site_url' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
        . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '/',
];

// Tabelas que o sistema usa. As do schema.sql original e as de cada migração.
$coreTables = ['users', 'leagues', 'matches', 'bolao_groups', 'group_leagues', 'group_members', 'predictions'];
$migrationTables = [
    'password_resets' => '001_password_resets_login_attempts.sql',
    'login_attempts' => '001_password_resets_login_attempts.sql',
    'teams' => '002_ligas_times_rodadas.sql',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['site_name'] = trim($_POST['site_name'] ?? '') ?: 'Conferência Bolão';
    $values['host'] = trim($_POST['host'] ?? '') ?: 'localhost';
    $values['name'] = trim($_POST['name'] ?? '');
    $values['user'] = trim($_POST['user'] ?? '');
    $values['admin_email'] = strtolower(trim($_POST['admin_email'] ?? ''));
    $values['site_url'] = trim($_POST['site_url'] ?? '');
    $password = (string) ($_POST['pass'] ?? '');

    if ($values['name'] === '' || $values['user'] === '') {
        $errors[] = 'Informe o nome do banco e o usuário do banco.';
    }
    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido para o administrador.';
    }
    if (!preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$#', $values['site_url'])) {
        $errors[] = 'Informe o endereço do site, como https://bolao.exemplo.com/.';
    } else {
        $values['site_url'] = rtrim($values['site_url'], '/') . '/';
    }
    if (!is_writable($root . '/includes')) {
        $errors[] = 'A pasta includes/ precisa ter permissão de escrita.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $values['host'] . ';dbname=' . $values['name'] . ';charset=utf8mb4',
                $values['user'],
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $ex) {
            $errors[] = 'Não consegui conectar ao banco. Confira nome, usuário e senha.';
        }
    }

    if ($pdo && !$errors) {
        $existing = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $missingCore = array_diff($coreTables, $existing);
        if ($missingCore) {
            $errors[] = 'As tabelas não foram encontradas (' . implode(', ', $missingCore) . '). Importe o arquivo schema.sql no phpMyAdmin antes de instalar.';
        } else {
            $missingFiles = [];
            foreach ($migrationTables as $table => $file) {
                if (!in_array($table, $existing, true)) {
                    $missingFiles[$file] = true;
                }
            }
            if ($missingFiles) {
                $errors[] = 'Faltam tabelas novas. Importe no phpMyAdmin: sql/migrations/' . implode(', sql/migrations/', array_keys($missingFiles)) . '.';
            }
        }
    }

    if ($pdo && !$errors) {
        $config = "<?php\n"
            . "// Gerado pelo instalador. Não compartilhe este arquivo.\n"
            . "define('DB_HOST', " . var_export($values['host'], true) . ");\n"
            . "define('DB_NAME', " . var_export($values['name'], true) . ");\n"
            . "define('DB_USER', " . var_export($values['user'], true) . ");\n"
            . "define('DB_PASS', " . var_export($password, true) . ");\n\n"
            . "define('ADMIN_EMAIL', " . var_export($values['admin_email'], true) . ");\n"
            . "define('SITE_NAME', " . var_export($values['site_name'], true) . ");\n"
            . "define('BASE_URL', '/');\n"
            . "// Endereço público do site, usado nos links enviados por e-mail.\n"
            . "define('SITE_URL', " . var_export($values['site_url'], true) . ");\n"
            . "// Remetente dos e-mails. Vazio = no-reply@ o domínio do site.\n"
            . "define('MAIL_FROM', '');\n"
            . "define('DEBUG', false);\n\n"
            . "if (DEBUG) {\n"
            . "    ini_set('display_errors', '1');\n"
            . "    error_reporting(E_ALL);\n"
            . "} else {\n"
            . "    ini_set('display_errors', '0');\n"
            . "    error_reporting(0);\n"
            . "}\n";

        if (file_put_contents($configFile, $config) === false) {
            $errors[] = 'Não consegui gravar includes/config.php.';
        } else {
            $done = true;
            // Remove o instalador. Se o servidor não permitir, apague manualmente.
            @unlink(__FILE__);
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalação do bolão</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <h1>Instalação do bolão</h1>

    <?php if ($done): ?>
        <p>Instalação concluída. A conexão foi confirmada e o arquivo de configuração foi gravado.</p>
        <p>Agora abra o site, crie a conta com o e-mail <strong><?= h($values['admin_email']) ?></strong> e você terá acesso à administração.</p>
        <p><a href="./">Ir para o site</a></p>
        <?php if (file_exists(__FILE__)): ?>
            <div class="alert"><p>Não consegui apagar o install.php. Apague esse arquivo pelo gerenciador de arquivos.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="muted">Preencha com os dados do banco MySQL criado no hPanel. Os nomes devem ser os completos, com o prefixo da conta (ex.: u329075197_bolao).</p>

        <form method="post" action="install.php">
            <label>Nome do site
                <input type="text" name="site_name" value="<?= h($values['site_name']) ?>" maxlength="60">
            </label>
            <label>Servidor do banco
                <input type="text" name="host" value="<?= h($values['host']) ?>">
            </label>
            <label>Nome do banco
                <input type="text" name="name" value="<?= h($values['name']) ?>" required>
            </label>
            <label>Usuário do banco
                <input type="text" name="user" value="<?= h($values['user']) ?>" required>
            </label>
            <label>Senha do banco
                <input type="password" name="pass">
            </label>
            <label>Endereço do site
                <input type="url" name="site_url" value="<?= h($values['site_url']) ?>" required>
            </label>
            <label>E-mail do administrador
                <input type="email" name="admin_email" value="<?= h($values['admin_email']) ?>" required>
            </label>
            <button type="submit">Instalar</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
