<?php
require_once __DIR__ . '/../includes/app.php';

$user = require_admin();
$errors = [];
$message = null;

$leagues = db()->query('SELECT id, name FROM leagues ORDER BY name')->fetchAll();
$validLeagueIds = array_map('intval', array_column($leagues, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_league') {
        $name = trim($_POST['league_name'] ?? '');
        $code = strtoupper(trim($_POST['league_code'] ?? ''));
        if ($name === '' || $code === '') {
            $errors[] = 'Informe nome e código da liga.';
        } else {
            db()->prepare('INSERT IGNORE INTO leagues (name, code) VALUES (?, ?)')->execute([$name, $code]);
            $message = 'Liga cadastrada.';
        }
    }

    if ($action === 'add_match') {
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $round = (int) ($_POST['round'] ?? 0);
        $home = trim($_POST['home_team'] ?? '');
        $away = trim($_POST['away_team'] ?? '');
        $kickoff = \DateTime::createFromFormat('Y-m-d\TH:i', $_POST['kickoff'] ?? '');

        if (!in_array($leagueId, $validLeagueIds, true)) {
            $errors[] = 'Escolha uma liga válida.';
        }
        if ($round < 1 || $round > 999) {
            $errors[] = 'Informe a rodada.';
        }
        if ($home === '' || $away === '') {
            $errors[] = 'Informe os dois times.';
        }
        if (!$kickoff) {
            $errors[] = 'Informe a data e o horário do jogo.';
        }

        if (!$errors) {
            db()->prepare(
                'INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([$leagueId, $round, $home, $away, $kickoff->format('Y-m-d H:i:00')]);
            $message = 'Jogo cadastrado.';
        }
    }

    if ($action === 'score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $home = trim((string) ($_POST['home_score'] ?? ''));
        $away = trim((string) ($_POST['away_score'] ?? ''));

        if (!ctype_digit($home) || !ctype_digit($away) || (int) $home > 30 || (int) $away > 30) {
            $errors[] = 'Informe um placar válido (0 a 30).';
        } else {
            db()->prepare('UPDATE matches SET home_score = ?, away_score = ? WHERE id = ?')
                ->execute([(int) $home, (int) $away, $matchId]);
            recalculate_match($matchId);
            $message = 'Resultado salvo e pontos recalculados.';
        }
    }

    if ($action === 'clear_score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        db()->prepare('UPDATE matches SET home_score = NULL, away_score = NULL WHERE id = ?')->execute([$matchId]);
        recalculate_match($matchId);
        $message = 'Resultado removido.';
    }
}

$matches = db()->query(
    'SELECT m.*, l.name AS league_name
     FROM matches m
     JOIN leagues l ON l.id = m.league_id
     ORDER BY m.kickoff_at DESC, m.id DESC
     LIMIT 200'
)->fetchAll();

page_start('Administração', $user, 'wrap');
?>
<h1>Administração</h1>

<?php alert_box($errors); ?>
<?php if ($message): ?><?php notice($message); ?><?php endif; ?>

<section class="panel">
    <h2>Nova liga</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_league">
        <input type="text" name="league_name" placeholder="Nome (ex.: Copa do Brasil)" required>
        <input type="text" name="league_code" placeholder="Código (ex.: BRA-CDB)" maxlength="20" required>
        <button type="submit" class="btn">Cadastrar liga</button>
    </form>
</section>

<section class="panel">
    <h2>Novo jogo</h2>
    <form method="post" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_match">

        <label>Liga
            <select name="league_id" required>
                <?php foreach ($leagues as $league): ?>
                    <option value="<?= (int) $league['id'] ?>"><?= e($league['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Rodada
            <input type="number" name="round" min="1" max="999" required>
        </label>
        <label>Mandante
            <input type="text" name="home_team" maxlength="100" required>
        </label>
        <label>Visitante
            <input type="text" name="away_team" maxlength="100" required>
        </label>
        <label>Data e horário (horário de Brasília)
            <input type="datetime-local" name="kickoff" required>
        </label>

        <button type="submit" class="btn">Cadastrar jogo</button>
    </form>
</section>

<section class="panel">
    <h2>Jogos e resultados</h2>
    <div class="table-wrap">
        <table class="tbl">
            <thead>
                <tr><th>Liga</th><th>Rod.</th><th>Jogo</th><th>Início</th><th>Placar final</th></tr>
            </thead>
            <tbody>
            <?php foreach ($matches as $m): ?>
                <tr>
                    <td><?= e($m['league_name']) ?></td>
                    <td class="center"><?= (int) $m['round'] ?></td>
                    <td><?= e($m['home_team']) ?> x <?= e($m['away_team']) ?></td>
                    <td><?= e(fmt_kickoff($m['kickoff_at'])) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="score">
                            <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                            <input class="score" type="number" name="home_score" min="0" max="30"
                                   value="<?= $m['home_score'] !== null ? (int) $m['home_score'] : '' ?>" required>
                            <span>x</span>
                            <input class="score" type="number" name="away_score" min="0" max="30"
                                   value="<?= $m['away_score'] !== null ? (int) $m['away_score'] : '' ?>" required>
                            <button type="submit" class="btn small">Salvar</button>
                        </form>
                        <?php if ($m['home_score'] !== null): ?>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="clear_score">
                                <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                                <button type="submit" class="btn small secondary">Limpar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
page_end();
