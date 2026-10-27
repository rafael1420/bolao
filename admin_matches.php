<?php
require_once __DIR__ . '/includes/app.php';

$user = require_admin();

$tabs = [
    'lancar' => 'Lançar resultados',
    'jogos' => 'Jogos',
    'ligas' => 'Ligas',
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'lancar';

$fieldErrors = [];
$old = [];

/** Endereço desta página mantendo os filtros e a aba. */
function admin_url(array $params, string $anchor = ''): string
{
    $params = array_filter($params, function ($v) {
        return $v !== null && $v !== '' && $v !== 0;
    });
    return 'admin_matches.php' . ($params ? '?' . http_build_query($params) : '') . $anchor;
}

$filters = [
    'liga' => (int) ($_REQUEST['liga'] ?? 0),
    'rodada' => (int) ($_REQUEST['rodada'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_league') {
        $name = trim($_POST['league_name'] ?? '');
        $code = strtoupper(trim($_POST['league_code'] ?? ''));
        $old = ['league_name' => $name, 'league_code' => $code];

        if ($name === '') {
            $fieldErrors['league_name'] = 'Informe o nome da liga.';
        }
        if ($code === '') {
            $fieldErrors['league_code'] = 'Informe o código da liga, como BRA-A.';
        }
        if (!$fieldErrors) {
            $stmt = db()->prepare('SELECT id FROM leagues WHERE code = ?');
            $stmt->execute([$code]);
            if ($stmt->fetch()) {
                $fieldErrors['league_code'] = 'Já existe uma liga com este código.';
            }
        }
        if (!$fieldErrors) {
            db()->prepare('INSERT INTO leagues (name, code) VALUES (?, ?)')->execute([$name, $code]);
            flash_set('notice', 'Liga cadastrada.');
            redirect(admin_url(['tab' => 'ligas']));
        }
        $tab = 'ligas';
    }

    if ($action === 'add_match') {
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $round = (int) ($_POST['round'] ?? 0);
        $home = trim($_POST['home_team'] ?? '');
        $away = trim($_POST['away_team'] ?? '');
        $kickoffRaw = (string) ($_POST['kickoff'] ?? '');
        $kickoff = \DateTime::createFromFormat('Y-m-d\TH:i', $kickoffRaw);
        $old = ['league_id' => $leagueId, 'round' => $round ?: '', 'home_team' => $home, 'away_team' => $away, 'kickoff' => $kickoffRaw];

        $leagueIds = array_map('intval', array_column(db()->query('SELECT id FROM leagues')->fetchAll(), 'id'));
        if (!in_array($leagueId, $leagueIds, true)) {
            $fieldErrors['league_id'] = 'Escolha uma liga.';
        }
        if ($round < 1 || $round > 999) {
            $fieldErrors['round'] = 'Informe a rodada, de 1 a 999.';
        }
        if ($home === '') {
            $fieldErrors['home_team'] = 'Informe o mandante.';
        }
        if ($away === '') {
            $fieldErrors['away_team'] = 'Informe o visitante.';
        }
        if (!$kickoff) {
            $fieldErrors['kickoff'] = 'Informe a data e o horário do jogo.';
        }

        if (!$fieldErrors) {
            db()->prepare(
                'INSERT INTO matches (league_id, round, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([$leagueId, $round, $home, $away, $kickoff->format('Y-m-d H:i:00')]);
            flash_set('notice', 'Jogo cadastrado.');
            redirect(admin_url(['tab' => 'jogos']));
        }
        $tab = 'jogos';
    }

    if ($action === 'score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $home = trim((string) ($_POST['home_score'] ?? ''));
        $away = trim((string) ($_POST['away_score'] ?? ''));
        $anchor = '#jogo-' . $matchId;
        $back = admin_url(['tab' => 'lancar', 'liga' => $filters['liga'], 'rodada' => $filters['rodada']], $anchor);

        if (!ctype_digit($home) || !ctype_digit($away) || (int) $home > 30 || (int) $away > 30) {
            flash_set('alert', 'Informe um placar válido, de 0 a 30.');
            redirect($back);
        }

        db()->prepare('UPDATE matches SET home_score = ?, away_score = ? WHERE id = ?')
            ->execute([(int) $home, (int) $away, $matchId]);
        recalculate_match($matchId);

        $stmt = db()->prepare('SELECT COUNT(*) FROM predictions WHERE match_id = ?');
        $stmt->execute([$matchId]);
        flash_set('notice', 'Salvo. ' . plural((int) $stmt->fetchColumn(), 'palpite recalculado', 'palpites recalculados') . '.');
        redirect($back);
    }

    if ($action === 'clear_score') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        db()->prepare('UPDATE matches SET home_score = NULL, away_score = NULL WHERE id = ?')->execute([$matchId]);
        recalculate_match($matchId);
        flash_set('notice', 'Resultado apagado. Os pontos deste jogo foram zerados.');
        redirect(admin_url(['tab' => 'lancar', 'liga' => $filters['liga'], 'rodada' => $filters['rodada']], '#jogo-' . $matchId));
    }
}

$leagues = db()->query('SELECT id, name, code FROM leagues ORDER BY name')->fetchAll();

page_start('Administração', $user, 'wrap', 'admin');
?>
<h1 class="h-page">Administração</h1>

<nav class="tabs" aria-label="Seções da administração">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tab" href="admin_matches.php?tab=<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'lancar'):
    $filterLiga = (int) ($_GET['liga'] ?? 0);
    $filterRodada = (int) ($_GET['rodada'] ?? 0);

    $where = [];
    $params = [];
    if ($filterLiga) {
        $where[] = 'm.league_id = ?';
        $params[] = $filterLiga;
    }
    if ($filterRodada) {
        $where[] = 'm.round = ?';
        $params[] = $filterRodada;
    }
    $sql = 'SELECT m.*, l.name AS league_name FROM matches m JOIN leagues l ON l.id = m.league_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY m.kickoff_at, m.id LIMIT 1000';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $now = time();
    $waiting = [];
    $upcoming = [];
    $finished = [];
    foreach ($rows as $m) {
        if ($m['home_score'] !== null) {
            $finished[] = $m;
        } elseif (strtotime($m['kickoff_at']) <= $now) {
            $waiting[] = $m;
        } else {
            $upcoming[] = $m;
        }
    }
    $finished = array_reverse($finished);
    $filtersParams = ['tab' => 'lancar', 'liga' => $filterLiga ?: null, 'rodada' => $filterRodada ?: null];
    ?>

    <form method="get" action="admin_matches.php" class="filters">
        <input type="hidden" name="tab" value="lancar">
        <div class="field">
            <label for="f-liga">Liga</label>
            <div class="control">
                <select id="f-liga" name="liga">
                    <option value="">Todas as ligas</option>
                    <?php foreach ($leagues as $league): ?>
                        <option value="<?= (int) $league['id'] ?>"<?= $filterLiga === (int) $league['id'] ? ' selected' : '' ?>><?= e($league['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="f-rodada">Rodada</label>
            <div class="control">
                <input id="f-rodada" type="number" name="rodada" min="1" max="999" inputmode="numeric" value="<?= $filterRodada ?: '' ?>">
            </div>
        </div>
        <div class="actions">
            <button type="submit" class="btn ghost">Filtrar</button>
            <?php if ($filterLiga || $filterRodada): ?>
                <a class="btn ghost" href="admin_matches.php?tab=lancar">Limpar filtros</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$rows): ?>
        <section class="panel empty-state">
            <?php if (!$filterLiga && !$filterRodada): ?>
                <h2>Nenhum jogo cadastrado.</h2>
                <p>Comece pelo cadastro de jogos.</p>
                <div class="actions"><a class="btn primary" href="admin_matches.php?tab=jogos">Cadastrar jogo</a></div>
            <?php else: ?>
                <h2>Nenhum jogo com esse filtro.</h2>
                <p>Tente outra liga ou rodada.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="admin-section" aria-labelledby="sec-aguardando">
        <h2 class="section-title" id="sec-aguardando">Aguardando placar</h2>
        <?php if (!$waiting): ?>
            <p class="empty-line">Nenhum jogo esperando placar. Tudo em dia.</p>
        <?php else: ?>
            <p class="meta"><?= e(plural(count($waiting), 'jogo esperando placar', 'jogos esperando placar')) ?>. Do mais antigo para o mais recente.</p>
            <ol class="admin-list">
                <?php foreach ($waiting as $m) { admin_row($m, $filtersParams); } ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($upcoming): ?>
    <section class="admin-section" aria-labelledby="sec-proximos">
        <h2 class="section-title" id="sec-proximos">Próximos jogos</h2>
        <ol class="admin-list">
            <?php foreach (array_slice($upcoming, 0, 50) as $m) { admin_row($m, $filtersParams); } ?>
        </ol>
    </section>
    <?php endif; ?>

    <?php if ($finished): ?>
    <section class="admin-section" aria-labelledby="sec-encerrados">
        <h2 class="section-title" id="sec-encerrados">Encerrados</h2>
        <p class="meta">Os 50 mais recentes. Salvar de novo recalcula os pontos.</p>
        <ol class="admin-list">
            <?php foreach (array_slice($finished, 0, 50) as $m) { admin_row($m, $filtersParams); } ?>
        </ol>
    </section>
    <?php endif; ?>

<?php elseif ($tab === 'jogos'):
    $matches = db()->query(
        'SELECT m.*, l.name AS league_name
         FROM matches m
         JOIN leagues l ON l.id = m.league_id
         ORDER BY m.kickoff_at DESC, m.id DESC
         LIMIT 200'
    )->fetchAll();
    ?>
    <details class="disclosure new-match"<?= $fieldErrors ? ' open' : '' ?>>
        <summary class="btn primary">Cadastrar jogo</summary>
        <div class="disclosure-body">
            <?php alert_box(array_values(array_filter($fieldErrors, function ($k) {
                return in_array($k, ['league_id', 'round', 'home_team', 'away_team', 'kickoff'], true);
            }, ARRAY_FILTER_USE_KEY))); ?>
            <form method="post" action="admin_matches.php?tab=jogos" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_match">
                <div class="field<?= isset($fieldErrors['league_id']) ? ' is-invalid' : '' ?>">
                    <label for="m-liga">Liga</label>
                    <div class="control">
                        <select id="m-liga" name="league_id" required>
                            <?php foreach ($leagues as $league): ?>
                                <option value="<?= (int) $league['id'] ?>"<?= (int) ($old['league_id'] ?? 0) === (int) $league['id'] ? ' selected' : '' ?>><?= e($league['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isset($fieldErrors['league_id'])): ?><p class="field-error"><?= e($fieldErrors['league_id']) ?></p><?php endif; ?>
                </div>
                <div class="grid-2">
                    <?php field('round', 'Rodada', ['type' => 'number', 'min' => 1, 'max' => 999, 'inputmode' => 'numeric', 'required' => true,
                        'value' => $old['round'] ?? '', 'error' => $fieldErrors['round'] ?? null]); ?>
                    <?php field('kickoff', 'Data e horário (Brasília)', ['type' => 'datetime-local', 'required' => true,
                        'value' => $old['kickoff'] ?? '', 'error' => $fieldErrors['kickoff'] ?? null]); ?>
                </div>
                <div class="grid-2">
                    <?php field('home_team', 'Mandante', ['maxlength' => 100, 'required' => true,
                        'value' => $old['home_team'] ?? '', 'error' => $fieldErrors['home_team'] ?? null]); ?>
                    <?php field('away_team', 'Visitante', ['maxlength' => 100, 'required' => true,
                        'value' => $old['away_team'] ?? '', 'error' => $fieldErrors['away_team'] ?? null]); ?>
                </div>
                <div class="actions"><button type="submit" class="btn primary" data-busy="Cadastrando…">Cadastrar jogo</button></div>
            </form>
        </div>
    </details>

    <section class="admin-section">
        <h2 class="section-title">Jogos cadastrados</h2>
        <?php if (!$matches): ?>
            <p class="empty-line">Nenhum jogo cadastrado. Comece pelo cadastro de jogos.</p>
        <?php else: ?>
            <p class="meta">Os 200 mais recentes. Para lançar um placar, use a aba Lançar resultados.</p>
            <ul class="list">
                <?php foreach ($matches as $m):
                    $ts = strtotime($m['kickoff_at']); ?>
                    <li class="list-row">
                        <span class="league"><?= e($m['league_name']) ?>, rodada <?= (int) $m['round'] ?></span>
                        <span class="list-teams"><?= e($m['home_team']) ?> × <?= e($m['away_team']) ?></span>
                        <span class="list-meta"><?= e(date('d/m', $ts) . ' às ' . kick_clock($ts)) ?></span>
                        <span class="list-meta"><?= $m['home_score'] !== null
                            ? 'Placar ' . (int) $m['home_score'] . ' × ' . (int) $m['away_score']
                            : 'Sem resultado' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php else: ?>
    <section class="admin-section">
        <h2 class="section-title">Ligas</h2>
        <?php if (!$leagues): ?>
            <p class="empty-line">Nenhuma liga cadastrada.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($leagues as $league): ?>
                    <li class="list-row">
                        <span class="list-teams"><?= e($league['name']) ?></span>
                        <span class="list-meta">Código <?= e($league['code']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel stack">
        <h2 class="section-title">Nova liga</h2>
        <form method="post" action="admin_matches.php?tab=ligas" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_league">
            <?php field('league_name', 'Nome', ['placeholder' => 'Ex.: Copa do Brasil', 'maxlength' => 100, 'required' => true,
                'value' => $old['league_name'] ?? '', 'error' => $fieldErrors['league_name'] ?? null]); ?>
            <?php field('league_code', 'Código', ['placeholder' => 'Ex.: BRA-CDB', 'maxlength' => 20, 'required' => true,
                'value' => $old['league_code'] ?? '', 'help' => 'Único. Não pode repetir o de outra liga.',
                'error' => $fieldErrors['league_code'] ?? null]); ?>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Cadastrando…">Cadastrar liga</button></div>
        </form>
    </section>
<?php endif;

page_end();

/** Uma linha de lançamento: times, horário e o formulário de placar. */
function admin_row(array $m, array $filters): void
{
    $id = (int) $m['id'];
    $ts = strtotime($m['kickoff_at']);
    $finished = $m['home_score'] !== null;
    $hidden = '';
    foreach ($filters as $k => $v) {
        if ($v !== null) {
            $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
        }
    }
    ?>
    <li class="admin-row" id="jogo-<?= $id ?>">
        <div class="admin-info">
            <span class="league"><?= e($m['league_name']) ?>, rodada <?= (int) $m['round'] ?></span>
            <p class="admin-teams"><?= e($m['home_team']) ?> <span class="vs" aria-hidden="true">×</span> <?= e($m['away_team']) ?></p>
            <span class="list-meta"><?= e(date('d/m', $ts) . ' às ' . kick_clock($ts)) ?></span>
        </div>

        <form method="post" action="admin_matches.php" class="score-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="score">
            <input type="hidden" name="match_id" value="<?= $id ?>">
            <?= $hidden ?>
            <label class="visually-hidden" for="s-h-<?= $id ?>">Gols de <?= e($m['home_team']) ?></label>
            <input id="s-h-<?= $id ?>" class="tile small" type="number" name="home_score" min="0" max="30" inputmode="numeric"
                   enterkeyhint="next" required value="<?= $finished ? (int) $m['home_score'] : '' ?>">
            <span class="colon" aria-hidden="true">:</span>
            <label class="visually-hidden" for="s-a-<?= $id ?>">Gols de <?= e($m['away_team']) ?></label>
            <input id="s-a-<?= $id ?>" class="tile small" type="number" name="away_score" min="0" max="30" inputmode="numeric"
                   enterkeyhint="done" required value="<?= $finished ? (int) $m['away_score'] : '' ?>">
            <button type="submit" class="btn primary small" data-busy="Salvando…">Salvar</button>
        </form>

        <?php if ($finished): ?>
            <details class="disclosure confirm admin-clear">
                <summary class="btn ghost small">Apagar resultado</summary>
                <div class="disclosure-body">
                    <p><strong><?= e($m['home_team']) ?> × <?= e($m['away_team']) ?></strong>: os pontos deste jogo serão zerados para todos os palpites.</p>
                    <form method="post" action="admin_matches.php" class="confirm-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_score">
                        <input type="hidden" name="match_id" value="<?= $id ?>">
                        <?= $hidden ?>
                        <button type="submit" class="btn danger" data-busy="Apagando…">Apagar resultado</button>
                        <a class="btn ghost" href="<?= e($_SERVER['REQUEST_URI'] ?? 'admin_matches.php') ?>" data-cancel>Cancelar</a>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </li>
    <?php
}
