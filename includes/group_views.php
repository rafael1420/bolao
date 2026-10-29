<?php
// Partes da tela do bolão (cabeçalho e cada aba). Carregadas por group.php.

function tab_href(int $groupId, string $tab, string $extra = ''): string
{
    return 'group.php?id=' . $groupId . '&amp;tab=' . $tab . $extra;
}

/** Endereço da própria página, para o link "Cancelar" dos disclosures (funciona sem JS). */
function self_href(): string
{
    return $_SERVER['REQUEST_URI'] ?? 'group.php';
}

function copy_box(string $id, string $label, string $value, string $shareId = ''): void
{
    ?>
    <div class="copy-box">
        <label class="copy-label" for="<?= e($id) ?>"><?= e($label) ?></label>
        <div class="copy-row">
            <input id="<?= e($id) ?>" type="text" readonly value="<?= e($value) ?>" data-select-on-focus>
        </div>
        <div class="copy-actions">
            <button type="button" class="btn ghost small" data-copy="<?= e($id) ?>"><?= icon('copy') ?>Copiar link</button>
            <button type="button" class="btn ghost small" data-share="<?= e($id) ?>" hidden><?= icon('share') ?>Compartilhar</button>
            <span class="copy-status" role="status" data-copy-status></span>
        </div>
    </div>
    <?php
}

/** Cabeçalho do bolão: caminho, nome, resumo, convite e abas. */
function group_header(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    $me = $c['me'];
    ?>
    <nav class="crumbs" aria-label="Você está em">
        <a href="index.php">Meus bolões</a>
        <span class="crumb-sep" aria-hidden="true">›</span>
        <span aria-current="page"><?= e($group['name']) ?></span>
    </nav>
    <a class="back" href="index.php"><?= icon('arrow-left') ?>Meus bolões</a>

    <header class="group-head">
        <div class="group-id">
            <?= avatar($group['name'], 'avatar-group avatar-lg') ?>
            <div class="group-title">
                <h1 class="h-page"><?= e($group['name']) ?></h1>
                <p class="league-chips"><?php foreach ($c['leagues'] as $league) { echo league_chip($league); } ?></p>
                <p class="head-chips">
                    <span class="pill is-muted"><?= icon('users') ?><?= e(plural($c['memberCount'], 'participante', 'participantes')) ?></span>
                    <?php if ($me): ?>
                        <span class="pill <?= (int) $me['pos'] === 1 ? 'is-pending' : 'is-muted' ?>"><?= icon('trophy') ?>Você em <?= (int) $me['pos'] ?>º, <?= e(plural((int) $me['total'], 'ponto', 'pontos')) ?></span>
                    <?php endif; ?>
                    <?php if ($c['isOwner']): ?><span class="tag">organiza</span><?php endif; ?>
                </p>
            </div>
        </div>

        <details class="disclosure invite">
            <summary class="btn ghost"><?= icon('share') ?>Convidar</summary>
            <div class="disclosure-body">
                <p>Mande o link ou o código para quem você quer no bolão.</p>
                <?php copy_box('invite-link', 'Link para os amigos', $c['inviteUrl']); ?>
                <p class="meta">Código: <strong class="code-text"><?= e($group['invite_code']) ?></strong></p>
                <p class="meta"><?= $group['password_hash'] !== null ? 'Este bolão pede senha para entrar.' : 'Este bolão não pede senha.' ?></p>
            </div>
        </details>
    </header>

    <nav class="tabs" aria-label="Seções do bolão">
        <?php
        $tabIcons = ['palpites' => 'calendar', 'ranking' => 'trophy', 'participantes' => 'users', 'configurar' => 'gear', 'regras' => 'list'];
        foreach ($c['tabs'] as $key => $label): ?>
            <a class="tab" href="<?= tab_href($gid, $key) ?>"<?= $c['tab'] === $key ? ' aria-current="page"' : '' ?>><?= icon($tabIcons[$key] ?? 'list') ?><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/** Texto do chip de rodada, com o estado para leitor de tela. */
function round_state(array $stat): array
{
    if ($stat['finished'] === $stat['total']) {
        return ['done', 'encerrada'];
    }
    if ($stat['pending'] > 0) {
        return ['pending', 'faltam ' . plural($stat['pending'], 'palpite', 'palpites')];
    }
    if ($stat['open'] > 0) {
        return ['ready', 'palpites em dia'];
    }
    return ['wait', 'aguardando resultados'];
}

/** Aba Palpites: rodada, faixa de rodadas, jogos e barra de salvar. */
function render_palpites(array $c): void
{
    $gid = $c['groupId'];
    $round = $c['round'];
    $now = $c['now'];
    $matches = $c['matches'];
    $mine = $c['mine'];
    $pf = $c['predictFlash'];
    $user = $c['user'];

    $openMatches = array_values(array_filter($matches, 'is_open'));
    $openCount = count($openMatches);
    $filled = 0;
    foreach ($openMatches as $m) {
        if (isset($mine[(int) $m['id']])) {
            $filled++;
        }
    }
    $firstOpen = $openCount ? strtotime($openMatches[0]['kickoff_at']) : null;

    $idx = array_search($round, $c['rounds'], true);
    $prevRound = ($idx !== false && $idx > 0) ? $c['rounds'][$idx - 1] : null;
    $nextRound = ($idx !== false && $idx < count($c['rounds']) - 1) ? $c['rounds'][$idx + 1] : null;
    $labels = $c['roundLabels'];
    $lq = $c['leagueQs'];

    if ($c['welcome']) {
        ?>
        <section class="panel welcome" aria-labelledby="welcome-title">
            <h2 id="welcome-title">Bolão criado. Mande o convite para a galera.</h2>
            <?php copy_box('welcome-link', 'Link do bolão', $c['inviteUrl']); ?>
            <p class="meta">Código: <strong class="code-text"><?= e($c['group']['invite_code']) ?></strong></p>
            <div class="actions">
                <a class="btn primary" href="#rodada">Dar meu primeiro palpite</a>
            </div>
        </section>
        <?php
    }
    ?>

    <?php render_league_tabs($c); ?>

    <?php if ($c['rounds']): ?>
    <nav class="rounds" aria-label="Rodadas">
        <?php if ($prevRound): ?>
            <a class="round-step" href="<?= tab_href($gid, 'palpites', $lq . '&amp;round=' . $prevRound) ?>" aria-label="Anterior: <?= e($labels[$prevRound]) ?>"><?= icon('chevron-l') ?></a>
        <?php else: ?>
            <span class="round-step is-off" aria-hidden="true"><?= icon('chevron-l') ?></span>
        <?php endif; ?>

        <ol class="round-strip" data-current-scroll>
            <?php foreach ($c['rounds'] as $r):
                $stat = $c['roundStats'][$r];
                [$state, $stateText] = round_state($stat);
                $isCurrent = $r === $round;
            ?>
                <li>
                    <a class="chip is-<?= e($state) ?>" href="<?= tab_href($gid, 'palpites', $lq . '&amp;round=' . $r) ?>" title="<?= e($labels[$r]) ?>"<?= $isCurrent ? ' aria-current="page" data-current' : '' ?>>
                        <span class="chip-num"><?= (int) $r ?></span>
                        <span class="visually-hidden">, <?= e($labels[$r]) ?>, <?= e($stateText) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($nextRound): ?>
            <a class="round-step" href="<?= tab_href($gid, 'palpites', $lq . '&amp;round=' . $nextRound) ?>" aria-label="Próxima: <?= e($labels[$nextRound]) ?>"><?= icon('chevron-r') ?></a>
        <?php else: ?>
            <span class="round-step is-off" aria-hidden="true"><?= icon('chevron-r') ?></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <section class="round-head" id="rodada">
        <h2 class="round-title"><?= e($labels[$round] ?? ('Rodada ' . (int) $round)) ?></h2>
        <?php render_round_jump($c); ?>
        <?php if (!$matches): ?>
            <p class="meta"><?= is_admin($user)
                ? 'Esta rodada ainda não tem jogos cadastrados. Cadastre-os no menu Administração.'
                : 'A tabela desta rodada ainda não saiu. Tente outra rodada.' ?></p>
        <?php elseif ($openCount): ?>
            <p class="meta">
                <?= e(plural($openCount, 'jogo aberto', 'jogos abertos')) ?>.
                Primeiro fechamento:
                <time data-kickoff data-first datetime="<?= e(date('c', $firstOpen)) ?>"><?= e(kickoff_phrase($firstOpen, $now, true)) ?></time>
            </p>
        <?php else: ?>
            <p class="meta">Rodada encerrada para palpites.
                <a href="<?= tab_href($gid, 'ranking') ?>">Ver o ranking</a>
            </p>
        <?php endif; ?>
    </section>

    <?php if ($pf): ?>
        <?php if ($pf['skipped']): ?>
            <div class="alert" role="alert">
                <p>
                    <?= $pf['saved'] > 0
                        ? e('Salvamos ' . plural($pf['saved'], 'palpite', 'palpites') . '.')
                        : 'Nenhum palpite foi salvo.' ?>
                    <?= e(plural(count($pf['skipped']), 'ficou de fora', 'ficaram de fora')) ?>. Veja abaixo.
                </p>
                <ul class="skipped">
                    <?php foreach ($pf['skipped'] as $s): ?>
                        <li><a href="#jogo-<?= (int) $s['id'] ?>"><?= e($s['label']) ?></a>: <?= e($s['reason']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($pf['saved'] > 0): ?>
            <div class="notice" role="status">
                <p>Palpites salvos às <?= e($pf['time']) ?>. Dá para mudar até cada jogo começar.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$matches): ?>
        <section class="panel empty-state"><p>Nenhum jogo nesta rodada.</p></section>
        <?php return; ?>
    <?php endif; ?>

    <form method="post" action="predict.php" class="matches-form" data-dirty-guard>
        <?= csrf_field() ?>
        <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
        <input type="hidden" name="league" value="<?= (int) $c['leagueId'] ?>">
        <input type="hidden" name="round" value="<?= (int) $round ?>">

        <ol class="matches">
        <?php foreach ($matches as $m):
            $id = (int) $m['id'];
            $pred = $mine[$id] ?? null;
            $open = is_open($m);
            $finished = $m['home_score'] !== null;
            $kick = strtotime($m['kickoff_at']);
            $keep = ($pf && isset($pf['keep'][$id])) ? $pf['keep'][$id] : null;
            $skipReason = null;
            if ($pf) {
                foreach ($pf['skipped'] as $s) {
                    if ((int) $s['id'] === $id) {
                        $skipReason = $s['reason'];
                    }
                }
            }
            $homeVal = $keep ? $keep['home'] : ($pred ? (string) (int) $pred['home_pred'] : '');
            $awayVal = $keep ? $keep['away'] : ($pred ? (string) (int) $pred['away_pred'] : '');
            $pts = $pred ? (int) ($pred['points'] ?? 0) : 0;
            $reason = ($pred && $finished)
                ? points_reason((int) $pred['home_pred'], (int) $pred['away_pred'], (int) $m['home_score'], (int) $m['away_score'])
                : '';
        ?>
            <?php
            // Estado do cartão: aberto (falta ou salvo), fechando em breve, fechado, encerrado (exato, acerto ou erro)
            $soon = $open && ($kick - $now) < 3600;
            $exact = $finished && $pred
                && (int) $pred['home_pred'] === (int) $m['home_score'] && (int) $pred['away_pred'] === (int) $m['away_score'];
            $live = !$open && is_live($m);
            $cardClass = $open ? 'is-open' : 'is-closed';
            if ($live) {
                $cardClass .= ' is-live';
            }
            if ($open) {
                $cardClass .= ($pred && !$skipReason) ? ' is-saved' : ' is-missing';
            }
            if ($soon) {
                $cardClass .= ' is-soon';
            }
            if ($finished) {
                $cardClass .= ' is-finished' . ($pred ? ($exact ? ' is-exact' : ($pts > 0 ? ' is-hit' : ' is-miss')) : ' is-miss');
            }
            $when = $open ? kick_day_clock($kick, $now) : date('d/m', $kick) . ', ' . kick_clock($kick);
            ?>
            <li class="match <?= $cardClass ?>" id="jogo-<?= $id ?>"
                data-kick="<?= $kick ?>" data-open="<?= $open ? '1' : '0' ?>">
                <div class="match-top">
                    <span class="league"><?= e($m['league_name']) ?> · <?= e($when) ?><?= !empty($m['note']) ? ' · ' . e($m['note']) : '' ?></span>
                    <?php if ($open): ?>
                        <?php if ($skipReason): ?>
                            <span class="badge is-warn"><?= icon('alert') ?>Não salvo</span>
                        <?php elseif ($pred): ?>
                            <span class="badge is-saved"><?= icon('check') ?>Palpite salvo</span>
                        <?php else: ?>
                            <span class="badge is-missing"><?= icon('alert') ?>Falta palpitar</span>
                        <?php endif; ?>
                    <?php elseif ($finished): ?>
                        <span class="badge is-final"><?= icon('whistle') ?>Encerrado</span>
                    <?php elseif ($live): ?>
                        <span class="badge is-live"><span class="live-dot" aria-hidden="true"></span>Ao vivo<?= !empty($m['status_detail']) ? ' · ' . e($m['status_detail']) : '' ?></span>
                    <?php else: ?>
                        <span class="badge is-muted"><?= icon('lock') ?><?= $pred ? 'Fechado' : 'Sem palpite' ?></span>
                    <?php endif; ?>
                </div>

                <div class="board">
                    <div class="side">
                        <?= team_badge($m, 'home') ?>
                        <?php if ($open): ?>
                            <label class="team" for="h-<?= $id ?>"><?= e($m['home_team']) ?></label>
                        <?php else: ?>
                            <span class="team"><?= e($m['home_team']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($open): ?>
                        <div class="score" role="group" aria-label="Seu palpite para <?= e($m['home_team']) ?> × <?= e($m['away_team']) ?>">
                            <div class="tile-set">
                                <button type="button" class="step" data-step="1" data-target="h-<?= $id ?>" hidden aria-label="Aumentar o placar de <?= e($m['home_team']) ?>"><?= icon('plus') ?></button>
                                <input id="h-<?= $id ?>" class="tile" type="number" inputmode="numeric" min="0" max="30" step="1"
                                       enterkeyhint="next" autocomplete="off" placeholder="–"
                                       name="preds[<?= $id ?>][home]" value="<?= e($homeVal) ?>">
                                <button type="button" class="step" data-step="-1" data-target="h-<?= $id ?>" hidden aria-label="Diminuir o placar de <?= e($m['home_team']) ?>"><?= icon('minus') ?></button>
                            </div>
                            <span class="colon" aria-hidden="true">:</span>
                            <div class="tile-set">
                                <button type="button" class="step" data-step="1" data-target="a-<?= $id ?>" hidden aria-label="Aumentar o placar de <?= e($m['away_team']) ?>"><?= icon('plus') ?></button>
                                <input id="a-<?= $id ?>" class="tile" type="number" inputmode="numeric" min="0" max="30" step="1"
                                       enterkeyhint="next" autocomplete="off" placeholder="–"
                                       name="preds[<?= $id ?>][away]" value="<?= e($awayVal) ?>">
                                <button type="button" class="step" data-step="-1" data-target="a-<?= $id ?>" hidden aria-label="Diminuir o placar de <?= e($m['away_team']) ?>"><?= icon('minus') ?></button>
                            </div>
                        </div>
                    <?php elseif ($finished): ?>
                        <div class="score">
                            <span class="visually-hidden">Placar final: <?= (int) $m['home_score'] ?> a <?= (int) $m['away_score'] ?>.</span>
                            <span class="tile is-official" aria-hidden="true"><?= (int) $m['home_score'] ?></span>
                            <span class="colon" aria-hidden="true">:</span>
                            <span class="tile is-official" aria-hidden="true"><?= (int) $m['away_score'] ?></span>
                        </div>
                    <?php elseif ($live): ?>
                        <div class="score">
                            <span class="visually-hidden">Placar parcial: <?= (int) $m['live_home_score'] ?> a <?= (int) $m['live_away_score'] ?>.</span>
                            <span class="tile is-live" aria-hidden="true"><?= (int) $m['live_home_score'] ?></span>
                            <span class="colon" aria-hidden="true">:</span>
                            <span class="tile is-live" aria-hidden="true"><?= (int) $m['live_away_score'] ?></span>
                        </div>
                    <?php elseif ($pred): ?>
                        <div class="score">
                            <span class="visually-hidden">Seu palpite: <?= (int) $pred['home_pred'] ?> a <?= (int) $pred['away_pred'] ?>.</span>
                            <span class="tile is-locked" aria-hidden="true"><?= (int) $pred['home_pred'] ?></span>
                            <span class="colon" aria-hidden="true">:</span>
                            <span class="tile is-locked" aria-hidden="true"><?= (int) $pred['away_pred'] ?></span>
                        </div>
                    <?php else: ?>
                        <div class="score">
                            <span class="visually-hidden">Sem palpite.</span>
                            <span class="tile is-empty" aria-hidden="true">–</span>
                            <span class="colon" aria-hidden="true">:</span>
                            <span class="tile is-empty" aria-hidden="true">–</span>
                        </div>
                    <?php endif; ?>

                    <div class="side">
                        <?= team_badge($m, 'away') ?>
                        <?php if ($open): ?>
                            <label class="team" for="a-<?= $id ?>"><?= e($m['away_team']) ?></label>
                        <?php else: ?>
                            <span class="team"><?= e($m['away_team']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="match-foot">
                    <?php if ($open): ?>
                        <p class="status">
                            <?= icon('clock') ?>
                            <?php if ($skipReason): ?>
                                <span class="status-text is-warn"><?= e('Não salvo: ' . $skipReason) ?></span>
                            <?php else: ?>
                                <time data-kickoff datetime="<?= e(date('c', $kick)) ?>" class="countdown"><?= e(kickoff_phrase($kick, $now)) ?></time>
                            <?php endif; ?>
                        </p>
                    <?php elseif ($finished): ?>
                        <p class="status">
                            <?php if ($pred): ?>
                                <span class="your-pick">Seu palpite <span class="pick-score"><?= (int) $pred['home_pred'] ?> × <?= (int) $pred['away_pred'] ?></span></span>
                            <?php else: ?>
                                <span class="your-pick">Você não palpitou</span>
                            <?php endif; ?>
                        </p>
                        <p class="points-badge<?= $pts > 0 ? ' is-scored' : '' ?><?= $exact ? ' is-exact' : '' ?>">
                            <strong><?= $pts > 0 ? '+' : '' ?><?= $pts ?></strong>
                            <span class="reason"><?= $pts === 1 ? 'ponto' : 'pontos' ?> · <?= e($pred ? $reason : 'Sem palpite') ?></span>
                        </p>
                    <?php elseif ($live): ?>
                        <p class="status">
                            <?php if ($pred): ?>
                                <span class="your-pick">Seu palpite <span class="pick-score"><?= (int) $pred['home_pred'] ?> × <?= (int) $pred['away_pred'] ?></span></span>
                            <?php else: ?>
                                <span class="your-pick">Você não palpitou</span>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="status">
                            <?= icon('lock') ?>
                            <span>Fechou às <?= e(kick_clock($kick)) ?></span>
                            <span class="status-text">· <?= $pred ? 'Aguardando o resultado' : 'Você não palpitou' ?></span>
                        </p>
                    <?php endif; ?>
                </div>
                <?php if (isset($c['others'][$id])) { render_others_predictions($c['others'][$id], (int) $user['id'], $finished); } ?>
            </li>
        <?php endforeach; ?>
        </ol>

        <?php if ($openCount): ?>
            <?php $justSaved = $pf && !$pf['skipped'] && $pf['saved'] > 0; ?>
            <div class="save-bar" data-save-bar>
                <div class="progress<?= $filled === $openCount ? ' is-full' : '' ?>" aria-hidden="true"><span class="progress-fill" data-progress style="width: <?= (int) round($filled * 100 / $openCount) ?>%"></span></div>
                <p class="save-count">
                    <span><strong data-filled><?= (int) $filled ?></strong> de <strong data-total><?= (int) $openCount ?></strong> preenchidos</span>
                    <span class="badge is-dirty" data-dirty hidden>Não salvo</span>
                </p>
                <p class="save-status<?= $justSaved ? ' is-saved' : '' ?>" aria-live="polite" data-save-status>
                    <?= $pf && !$pf['skipped'] && $pf['saved'] > 0
                        ? e('Palpites salvos às ' . $pf['time'] . '. Dá para mudar até cada jogo começar.')
                        : 'Dá para mudar até cada jogo começar.' ?>
                </p>
                <button type="submit" class="btn primary" data-busy="Salvando…">Salvar palpites</button>
            </div>
        <?php endif; ?>
    </form>
    <?php
}

/** Aba Ranking: minha posição e a tabela com empates na mesma posição. */
function render_ranking(array $c): void
{
    $me = $c['me'];
    $above = $c['above'];
    ?>
    <h2 class="section-title">Ranking</h2>

    <?php if ($me): ?>
        <section class="panel standing" aria-live="polite">
            <span class="standing-pos" aria-hidden="true"><?= (int) $me['pos'] ?>º</span>
            <p class="standing-line">
                <?php if ((int) $me['pos'] === 1): ?>
                    Você lidera com <?= (int) $me['total'] ?> pontos.
                <?php else: ?>
                    Você está em <?= (int) $me['pos'] ?>º, com <?= (int) $me['total'] ?> pontos.
                    <?php if ($above): ?>
                        Faltam <?= (int) ($above['total'] - $me['total']) ?> para o <?= (int) $above['pos'] ?>º.
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </section>
    <?php endif; ?>

    <p class="meta">Atualizado a cada resultado lançado. Desempate: placares exatos, depois acertos de vencedor ou empate. Quem empata em tudo divide a mesma posição.</p>

    <?php render_ranking_selector($c); ?>

    <?php
    // Pódio: os três primeiros da lista mostrada (geral ou rodada), só quando já há pontos.
    // Repete o topo da tabela de forma visual; a tabela continua completa para leitores de tela.
    $rows = $c['rankRound'] ? $c['roundRanking'] : $c['ranking'];
    $top = array_slice($rows, 0, 3);
    if (count($top) >= 2 && (int) $top[0]['total'] > 0): ?>
        <ol class="podium" aria-hidden="true">
            <?php foreach ($top as $i => $row):
                $isMe = (int) $row['id'] === (int) $c['user']['id'];
            ?>
                <li class="podium-spot p<?= $i + 1 ?><?= $isMe ? ' is-me' : '' ?>">
                    <span class="podium-crown"><?= $i === 0 ? icon('trophy') : '' ?></span>
                    <?= avatar($row['name']) ?>
                    <span class="podium-name"><?= e($row['name']) ?><?= $isMe ? ' <span class="muted-small">você</span>' : '' ?></span>
                    <span class="podium-step">
                        <span class="podium-place"><?= (int) $row['pos'] ?>º</span>
                        <span class="podium-pts"><?= e(plural((int) $row['total'], 'ponto', 'pontos')) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="ranking">
            <caption class="visually-hidden"><?= $c['rankRound'] ? e('Classificação: ' . $c['rankRound']['label']) : 'Classificação do bolão' ?></caption>
            <thead>
                <tr><th scope="col" class="col-pos">Posição</th><th scope="col">Participante</th><th scope="col" class="col-pts">Pontos</th><th scope="col" class="col-exact"><abbr title="Placares exatos">Exatos</abbr></th><th scope="col" class="col-hits"><abbr title="Acertos de vencedor ou empate (inclui os exatos)">Acertos</abbr></th><th scope="col" class="col-played"><abbr title="Palpites em jogos encerrados">Jogos</abbr></th></tr>
            </thead>
            <tbody>
            <?php foreach (($c['rankRound'] ? $c['roundRanking'] : $c['ranking']) as $row):
                $isMe = (int) $row['id'] === (int) $c['user']['id'];
            ?>
                <tr class="<?= $isMe ? 'is-me ' : '' ?>top-<?= (int) $row['pos'] ?>">
                    <th scope="row" class="col-pos"><?= (int) $row['pos'] ?>º</th>
                    <td><span class="who"><?= avatar($row['name']) ?><span class="who-name"><?= e($row['name']) ?><?= $isMe ? ' <span class="you">você</span>' : '' ?></span></span></td>
                    <td class="col-pts"><?= (int) $row['total'] ?></td>
                    <td class="col-exact"><?= (int) $row['exact'] ?></td>
                    <td class="col-hits"><?= (int) $row['outcomes'] ?></td>
                    <td class="col-played"><?= (int) $row['played'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/** Aba Participantes: nome e pontos. Só o organizador vê o botão Remover. */
function render_participantes(array $c): void
{
    $gid = $c['groupId'];
    $ownerId = (int) $c['group']['owner_id'];
    $isOwner = $c['isOwner'];
    ?>
    <h2 class="section-title">Participantes (<?= (int) $c['memberCount'] ?>)</h2>

    <ul class="people">
        <?php foreach ($c['ranking'] as $row):
            $rowId = (int) $row['id'];
            $isOwnerRow = $rowId === $ownerId;
        ?>
            <li class="person">
                <div class="person-main">
                    <?= avatar($row['name']) ?>
                    <span class="person-text">
                        <span class="person-name"><?= e($row['name']) ?></span>
                        <?php if ($isOwnerRow): ?><span class="tag">organiza</span><?php endif; ?>
                        <?php if ($rowId === (int) $c['user']['id']): ?><span class="you">você</span><?php endif; ?>
                    </span>
                </div>
                <span class="person-points"><strong><?= (int) $row['total'] ?></strong> pontos</span>

                <?php if ($isOwner && !$isOwnerRow): ?>
                    <details class="disclosure confirm person-remove">
                        <summary class="btn ghost small">Remover</summary>
                        <div class="disclosure-body">
                            <p><strong>Remover <?= e($row['name']) ?>?</strong> Essa pessoa sai do bolão. Os palpites dela ficam guardados e voltam se ela entrar de novo.</p>
                            <form method="post" action="members.php" class="confirm-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="user_id" value="<?= $rowId ?>">
                                <button type="submit" class="btn danger" data-busy="Removendo…">Remover participante</button>
                                <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

/** Mensagem de um bloco da aba Configurar (vinda do PRG). */
function config_block_notice(?array $flash, string $block): void
{
    if (!$flash || ($flash['block'] ?? '') !== $block) {
        return;
    }
    if (!empty($flash['errors'])) {
        alert_box($flash['errors']);
    } elseif (!empty($flash['ok'])) {
        notice($flash['ok']);
    }
}

function config_old(?array $flash, string $key, string $block, string $default): string
{
    if ($flash && ($flash['block'] ?? '') === $block && isset($flash['old'][$key])) {
        return (string) $flash['old'][$key];
    }
    return $default;
}

function config_error(?array $flash, string $block, string $key): ?string
{
    if ($flash && ($flash['block'] ?? '') === $block && isset($flash['field_errors'][$key])) {
        return $flash['field_errors'][$key];
    }
    return null;
}

/** Aviso de recálculo: conta os palpites que já têm pontos. */
function recalc_warning(array $scored): string
{
    if ($scored['preds'] === 0) {
        return 'Ainda não há jogos encerrados. Nada será recalculado.';
    }
    return 'Ao salvar, os pontos de ' . plural($scored['preds'], 'palpite', 'palpites')
        . ' de ' . plural($scored['matches'], 'jogo encerrado', 'jogos encerrados') . ' serão recalculados.';
}

/** Aba Configurar (só organizador). Cada bloco salva sozinho. */
function render_configurar(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    $flash = $c['configFlash'];
    $stmt = db()->prepare('SELECT league_id FROM group_leagues WHERE group_id = ?');
    $stmt->execute([$gid]);
    $currentLeagues = array_map('intval', array_column($stmt->fetchAll(), 'league_id'));
    if ($flash && ($flash['block'] ?? '') === 'ligas' && isset($flash['old']['leagues'])) {
        $currentLeagues = $flash['old']['leagues'];
    }
    $action = 'group_settings.php?id=' . $gid;
    $hasPassword = $group['password_hash'] !== null;
    ?>
    <h2 class="section-title">Configurar bolão</h2>

    <section class="block" id="bloco-nome">
        <h3><span class="block-icon"><?= icon('gear') ?></span>Dados do bolão</h3>
        <?php config_block_notice($flash, 'nome'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="name">
            <?php field('name', 'Nome do bolão', ['value' => config_old($flash, 'name', 'nome', $group['name']),
                'maxlength' => 100, 'required' => true, 'error' => config_error($flash, 'nome', 'name')]); ?>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar nome</button></div>
        </form>
    </section>

    <section class="block" id="bloco-entrada">
        <h3><span class="block-icon"><?= icon('lock') ?></span>Entrada</h3>
        <?php config_block_notice($flash, 'entrada'); ?>
        <p><?= $hasPassword
            ? 'Este bolão tem senha. Quem tiver o link e a senha entra.'
            : 'Este bolão não tem senha. Quem tiver o link entra direto.' ?></p>

        <details class="disclosure">
            <summary class="btn ghost"><?= $hasPassword ? 'Trocar senha' : 'Colocar senha' ?></summary>
            <div class="disclosure-body">
                <form method="post" action="<?= e($action) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="password">
                    <?php field('password', 'Nova senha', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true,
                        'help' => 'Mínimo de 4 caracteres.', 'error' => config_error($flash, 'entrada', 'password')]); ?>
                    <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar nova senha</button></div>
                </form>
            </div>
        </details>

        <?php if ($hasPassword): ?>
            <details class="disclosure confirm">
                <summary class="btn ghost">Remover senha</summary>
                <div class="disclosure-body">
                    <p>Sem senha, quem tiver o link entra direto.</p>
                    <form method="post" action="<?= e($action) ?>" class="confirm-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                        <input type="hidden" name="action" value="remove_password">
                        <button type="submit" class="btn danger" data-busy="Removendo…">Remover senha</button>
                        <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <section class="block" id="bloco-ligas">
        <h3><span class="block-icon"><?= icon('trophy') ?></span>Ligas</h3>
        <?php config_block_notice($flash, 'ligas'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="leagues">
            <?php render_league_picker(array_map('intval', $currentLeagues), 'cfg'); ?>
            <p class="help"><?= e(recalc_warning($c['scored'])) ?></p>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar ligas</button></div>
        </form>
    </section>

    <section class="block" id="bloco-rodadas">
        <h3><span class="block-icon"><?= icon('calendar') ?></span>Rodadas</h3>
        <?php config_block_notice($flash, 'rodadas'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="rounds">
            <?php render_mode_radios('round_mode', 'Quais rodadas aceitam palpite', ROUND_MODES, (string) ($group['round_mode'] ?? 'rodada')); ?>
            <?php render_mode_radios('deadline_mode', 'Prazo dos palpites', DEADLINE_MODES, (string) ($group['deadline_mode'] ?? 'jogo')); ?>
            <p class="help">Os pontos não mudam. Palpites já feitos continuam guardados.</p>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar rodadas</button></div>
        </form>
    </section>

    <section class="block" id="bloco-pontos">
        <h3><span class="block-icon"><?= icon('star') ?></span>Pontuação</h3>
        <?php config_block_notice($flash, 'pontos'); ?>
        <form method="post" action="<?= e($action) ?>" class="stack" data-dirty-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
            <input type="hidden" name="action" value="points">
            <div class="grid3">
                <?php field('exact_points', 'Placar exato', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-exact',
                    'value' => config_old($flash, 'exact', 'pontos', (string) (int) $group['exact_points'])]); ?>
                <?php field('winner_points', 'Vencedor ou empate', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-winner',
                    'value' => config_old($flash, 'winner', 'pontos', (string) (int) $group['winner_points'])]); ?>
                <?php field('goal_diff_points', 'Saldo de gols', ['type' => 'number', 'min' => 0, 'max' => 20, 'inputmode' => 'numeric', 'id' => 'cfg-diff',
                    'value' => config_old($flash, 'diff', 'pontos', (string) (int) $group['goal_diff_points'])]); ?>
            </div>
            <p class="help example" data-points-example aria-live="polite"><?= e(points_example(['exact_points' => (int) $group['exact_points'], 'winner_points' => (int) $group['winner_points'], 'goal_diff_points' => (int) $group['goal_diff_points']])) ?></p>
            <p class="help"><?= e(recalc_warning($c['scored'])) ?></p>
            <div class="actions"><button type="submit" class="btn primary" data-busy="Salvando…">Salvar pontuação</button></div>
        </form>
    </section>

    <section class="block" id="bloco-convite">
        <h3><span class="block-icon"><?= icon('share') ?></span>Convite</h3>
        <?php config_block_notice($flash, 'convite'); ?>
        <?php copy_box('cfg-invite-link', 'Link para os amigos', $c['inviteUrl']); ?>
        <p class="meta">Código atual: <strong class="code-text"><?= e($group['invite_code']) ?></strong></p>
        <details class="disclosure confirm">
            <summary class="btn ghost">Gerar novo código</summary>
            <div class="disclosure-body">
                <p>O link atual deixa de funcionar. Quem já está no bolão continua nele.</p>
                <form method="post" action="<?= e($action) ?>" class="confirm-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="new_code">
                    <button type="submit" class="btn danger" data-busy="Gerando…">Gerar novo código</button>
                    <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                </form>
            </div>
        </details>
    </section>

    <?php render_transfer_block($c, $action); ?>

    <section class="block danger-zone" id="bloco-perigo">
        <h3><span class="block-icon"><?= icon('alert') ?></span>Zona de perigo</h3>
        <?php config_block_notice($flash, 'perigo'); ?>
        <details class="disclosure confirm">
            <summary class="btn danger-outline">Excluir bolão</summary>
            <div class="disclosure-body">
                <p><strong>Excluir o bolão <?= e($group['name']) ?>?</strong> Somem os participantes, os palpites e o ranking. Não tem como desfazer.</p>
                <form method="post" action="<?= e($action) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <input type="hidden" name="action" value="delete">
                    <?php field('confirm_name', 'Digite o nome do bolão para confirmar', ['autocomplete' => 'off', 'required' => true,
                        'help' => 'Exatamente como aparece no topo: ' . $group['name'] . '.', 'error' => config_error($flash, 'perigo', 'confirm_name')]); ?>
                    <div class="confirm-actions">
                        <button type="submit" class="btn danger" data-requires="<?= e($group['name']) ?>" data-busy="Excluindo…">Excluir bolão</button>
                        <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                    </div>
                </form>
            </div>
        </details>
    </section>
    <?php
}

/** Grupo de opções (rádio) com nome e explicação de cada uma. */
function render_mode_radios(string $name, string $legend, array $options, string $current): void
{
    ?>
    <fieldset class="fieldset">
        <legend><?= e($legend) ?></legend>
        <?php foreach ($options as $value => [$label, $help]): $id = 'f-' . $name . '-' . $value; ?>
            <label class="check radio-card" for="<?= e($id) ?>">
                <input type="radio" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"<?= $value === $current ? ' checked' : '' ?>>
                <span class="check-label"><?= e($label) ?></span>
                <span class="check-meta muted-small"><?= e($help) ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php
}

/** Aba Regras (membros): pontuação e ligas, em leitura, e sair do bolão. */
function render_regras(array $c): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    ?>
    <h2 class="section-title">Regras</h2>

    <section class="block">
        <h3><span class="block-icon"><?= icon('star') ?></span>Pontuação</h3>
        <dl class="rules">
            <div><dt>Placar exato</dt><dd><?= (int) $group['exact_points'] ?> pontos</dd></div>
            <div><dt>Vencedor ou empate</dt><dd><?= (int) $group['winner_points'] ?> pontos</dd></div>
            <div><dt>Saldo de gols certo</dt><dd>+<?= (int) $group['goal_diff_points'] ?> pontos</dd></div>
        </dl>
        <p class="help">Os pontos de um palpite se somam. Quem acerta o placar exato não ganha os outros pontos.</p>
    </section>

    <section class="block">
        <h3><span class="block-icon"><?= icon('trophy') ?></span>Ligas</h3>
        <p class="league-chips"><?php foreach ($c['leagues'] as $league) { echo league_chip($league); } ?></p>
    </section>

    <section class="block">
        <h3><span class="block-icon"><?= icon('calendar') ?></span>Rodadas</h3>
        <?php
        $rm = ROUND_MODES[$group['round_mode'] ?? 'rodada'] ?? ROUND_MODES['rodada'];
        $dm = DEADLINE_MODES[$group['deadline_mode'] ?? 'jogo'] ?? DEADLINE_MODES['jogo'];
        ?>
        <dl class="rules">
            <div><dt>Palpites</dt><dd><?= e($rm[0]) ?></dd></div>
            <div><dt>Prazo</dt><dd><?= e($dm[0]) ?></dd></div>
        </dl>
        <p class="help"><?= e($rm[1] . ' ' . $dm[1]) ?></p>
    </section>

    <section class="block danger-zone">
        <h3><span class="block-icon"><?= icon('logout') ?></span>Sair do bolão</h3>
        <p>Você deixa de ver este bolão. Seus palpites ficam guardados se você voltar.</p>
        <details class="disclosure confirm">
            <summary class="btn danger-outline">Sair do bolão</summary>
            <div class="disclosure-body">
                <form method="post" action="leave_group.php" class="confirm-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                    <button type="submit" class="btn danger" data-busy="Saindo…">Sair do bolão</button>
                    <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                </form>
            </div>
        </details>
    </section>
    <?php
}

/**
 * Palpites de todos num jogo já fechado (aba Palpites). Só é chamado para jogos cujo
 * início já passou: antes disso os palpites alheios nunca aparecem.
 */
function render_others_predictions(array $rows, int $myId, bool $finished): void
{
    $count = 0;
    foreach ($rows as $r) {
        if ($r['home_pred'] !== null) {
            $count++;
        }
    }
    ?>
    <details class="disclosure others">
        <summary class="btn ghost small"><?= icon('eye') ?>Palpites da galera (<?= (int) $count ?>)</summary>
        <div class="disclosure-body">
            <ul class="others-list">
                <?php foreach ($rows as $r):
                    $isMe = $r['user_id'] === $myId;
                ?>
                    <li class="others-row<?= $isMe ? ' is-me' : '' ?><?= $r['home_pred'] === null ? ' is-missing' : '' ?>">
                        <span class="others-name"><?= e($r['name']) ?><?= $isMe ? ' <span class="muted-small">(você)</span>' : '' ?></span>
                        <span class="others-pick"><?= $r['home_pred'] === null ? '–' : (int) $r['home_pred'] . ' × ' . (int) $r['away_pred'] ?></span>
                        <?php if ($finished): ?>
                            <span class="others-points"><strong><?= (int) ($r['points'] ?? 0) ?></strong> <?= (int) ($r['points'] ?? 0) === 1 ? 'ponto' : 'pontos' ?></span>
                        <?php endif; ?>
                        <span class="others-reason muted-small"><?= e($r['reason']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>
    <?php
}

/** Seletor "Geral / Rodada N" da aba Ranking. Funciona sem JavaScript (formulário GET). */
function render_ranking_selector(array $c): void
{
    if (!$c['scoredRounds']) {
        return;
    }
    $gid = $c['groupId'];
    ?>
    <form method="get" action="group.php" class="ranking-filter">
        <input type="hidden" name="id" value="<?= (int) $gid ?>">
        <input type="hidden" name="tab" value="ranking">
        <div class="field">
            <label for="f-rank-rodada">Ver ranking</label>
            <div class="control">
                <select id="f-rank-rodada" name="rodada" data-autosubmit>
                    <option value=""<?= $c['rankRound'] === null ? ' selected' : '' ?>>Geral</option>
                    <?php foreach ($c['scoredRounds'] as $r): ?>
                        <option value="<?= e($r['key']) ?>"<?= $c['rankRound'] && $c['rankRound']['key'] === $r['key'] ? ' selected' : '' ?>><?= e($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="actions"><button type="submit" class="btn ghost small">Ver</button></div>
    </form>
    <?php if ($c['rankRound']): ?>
        <p class="meta">Pontos só dos jogos encerrados de <?= e($c['rankRound']['label']) ?>.</p>
    <?php endif; ?>
    <?php
}

/** Bloco "Transferir organização" da aba Configurar (só organizador). */
function render_transfer_block(array $c, string $action): void
{
    $group = $c['group'];
    $gid = $c['groupId'];
    $flash = $c['configFlash'];
    $ownerId = (int) $group['owner_id'];
    $candidates = array_values(array_filter($c['ranking'], function ($row) use ($ownerId) {
        return (int) $row['id'] !== $ownerId;
    }));
    usort($candidates, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    $selected = config_old($flash, 'new_owner_id', 'transferir', '');
    $error = config_error($flash, 'transferir', 'new_owner_id');
    $isOpen = $flash && ($flash['block'] ?? '') === 'transferir' && !empty($flash['errors']);
    ?>
    <section class="block" id="bloco-transferir">
        <h3><span class="block-icon"><?= icon('users') ?></span>Transferir organização</h3>
        <?php config_block_notice($flash, 'transferir'); ?>
        <?php if (!$candidates): ?>
            <p class="help">Ainda não há outros participantes. Convide alguém para poder passar a organização.</p>
        <?php else: ?>
            <p>Passe o bolão para outro participante. Ele poderá mudar regras, remover pessoas e excluir o bolão. Você continua como participante.</p>
            <details class="disclosure confirm"<?= $isOpen ? ' open' : '' ?>>
                <summary class="btn ghost">Transferir organização</summary>
                <div class="disclosure-body">
                    <form method="post" action="<?= e($action) ?>" class="stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="group_id" value="<?= (int) $gid ?>">
                        <input type="hidden" name="action" value="transfer">
                        <div class="field<?= $error ? ' is-invalid' : '' ?>">
                            <label for="f-new-owner">Novo organizador</label>
                            <div class="control">
                                <select id="f-new-owner" name="new_owner_id" required<?= $error ? ' aria-invalid="true" aria-describedby="f-new-owner-error"' : '' ?>>
                                    <option value="">Escolha um participante</option>
                                    <?php foreach ($candidates as $row): ?>
                                        <option value="<?= (int) $row['id'] ?>"<?= $selected === (string) (int) $row['id'] ? ' selected' : '' ?>><?= e($row['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($error): ?><p class="field-error" id="f-new-owner-error"><?= e($error) ?></p><?php endif; ?>
                        </div>
                        <?php field('confirm_name', 'Digite o nome do bolão para confirmar', ['id' => 'f-transfer-confirm', 'autocomplete' => 'off', 'required' => true,
                            'help' => 'Exatamente como aparece no topo: ' . $group['name'] . '.', 'error' => config_error($flash, 'transferir', 'confirm_name')]); ?>
                        <div class="confirm-actions">
                            <button type="submit" class="btn danger" data-requires="<?= e($group['name']) ?>" data-busy="Transferindo…">Transferir</button>
                            <a class="btn ghost" href="<?= e(self_href()) ?>" data-cancel>Cancelar</a>
                        </div>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Seletor de ligas agrupado por país (criar bolão e Configurar). Brasil, América do Sul e Europa
 * ficam abertos; os outros países em destaque se abrem num clique (ou já abertos, se têm liga marcada);
 * os demais ficam em "Mais países". A busca filtra os cartões (só com JavaScript).
 */
function render_league_picker(array $selected, string $idPrefix): void
{
    $rows = league_picker_rows($selected);
    $byCountry = [];
    foreach ($rows as $row) {
        $byCountry[$row['country']][] = $row;
    }
    $featured = $others = [];
    foreach ($byCountry as $code => $list) {
        if (COUNTRIES[$code][2] ?? false) {
            $featured[$code] = $list;
        } else {
            $others[$code] = $list;
        }
    }
    $othersSelected = 0;
    foreach ($others as $list) {
        foreach ($list as $row) {
            $othersSelected += in_array($row['id'], $selected, true) ? 1 : 0;
        }
    }
    ?>
    <div class="league-picker" data-league-picker data-max="<?= (int) MAX_GROUP_LEAGUES ?>">
        <p class="help league-count" aria-live="polite">Escolha até <?= (int) MAX_GROUP_LEAGUES ?> campeonatos. <strong data-league-count><?= e(plural(count($selected), 'selecionado', 'selecionados')) ?></strong></p>
        <div class="field league-search" hidden data-league-search-wrap>
            <label for="<?= e($idPrefix) ?>-busca">Buscar campeonato</label>
            <div class="control"><input type="search" id="<?= e($idPrefix) ?>-busca" autocomplete="off" placeholder="Ex.: Premier League, Copa do Brasil" data-league-search></div>
        </div>
        <?php foreach ($featured as $code => $list) {
            render_league_country($code, $list, $selected, $idPrefix, !in_array($code, ['BRA', 'CONMEBOL', 'UEFA'], true));
        } ?>
        <?php if ($others): ?>
            <details class="country-more" data-country<?= $othersSelected ? ' open' : '' ?>>
                <summary class="country-head"><?= icon('plus-circle') ?><span class="country-name">Mais países</span><span class="country-count" data-country-count><?= $othersSelected ? e(plural($othersSelected, 'selecionado', 'selecionados')) : '' ?></span></summary>
                <?php foreach ($others as $code => $list) {
                    render_league_country($code, $list, $selected, $idPrefix, false);
                } ?>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

/** Um país do seletor de ligas: bandeira, nome, contador e os cartões das ligas. */
function render_league_country(string $code, array $rows, array $selected, string $idPrefix, bool $collapsible): void
{
    $count = 0;
    foreach ($rows as $row) {
        $count += in_array($row['id'], $selected, true) ? 1 : 0;
    }
    $head = country_flag_svg($code) . '<span class="country-name">' . e(country_name($code)) . '</span>'
        . '<span class="country-count" data-country-count>' . ($count ? e(plural($count, 'selecionado', 'selecionados')) : '') . '</span>';
    $legendId = $idPrefix . '-pais-' . strtolower($code);
    if ($collapsible): ?>
        <details class="country-block" data-country<?= $count ? ' open' : '' ?>>
            <summary class="country-head"><?= $head ?></summary>
            <fieldset class="league-grid" aria-labelledby="<?= e($legendId) ?>">
                <legend class="visually-hidden" id="<?= e($legendId) ?>"><?= e(country_name($code)) ?></legend>
                <?php render_league_cards($rows, $selected, $idPrefix); ?>
            </fieldset>
        </details>
    <?php else: ?>
        <fieldset class="country-block is-static" data-country>
            <legend class="country-head"><?= $head ?></legend>
            <div class="league-grid"><?php render_league_cards($rows, $selected, $idPrefix); ?></div>
        </fieldset>
    <?php endif;
}

function render_league_cards(array $rows, array $selected, string $idPrefix): void
{
    foreach ($rows as $row) {
        $logo = espn_img($row['logo_url'] ?? null, 48);
        $extra = '<span class="check-meta">'
            . '<span class="tag">' . ($row['kind'] === 'copa' ? 'Copa' : 'Liga') . '</span>'
            . ($row['season_label'] ? '<span class="muted-small">' . e($row['season_label']) . '</span>' : '')
            . ($row['off_season'] ? '<span class="tag is-off">fora de temporada</span>' : '')
            . '</span>';
        if ($logo) {
            $extra .= '<img class="league-logo" src="' . e($logo) . '" width="24" height="24" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">';
        }
        $search = mb_strtolower($row['name'] . ' ' . ($row['short_name'] ?? '') . ' ' . country_name($row['country']), 'UTF-8');
        check_option('leagues[]', (string) $row['id'], $row['name'], in_array($row['id'], $selected, true),
            $idPrefix . '-liga-' . $row['id'], $extra, ' data-search="' . e($search) . '"');
    }
}

/** Nome dos modos de rodada e de prazo, para Configurar e Regras. */
const ROUND_MODES = [
    'rodada' => ['Rodada atual', 'Só a rodada da vez aceita palpite. A próxima abre quando todos os jogos da atual começarem, ou 48 h antes do primeiro jogo dela.'],
    'duas'   => ['Atual e próxima', 'Dá para palpitar na rodada da vez e na seguinte.'],
    'livre'  => ['Livre', 'Qualquer jogo já marcado aceita palpite até começar.'],
];
const DEADLINE_MODES = [
    'jogo'   => ['Até cada jogo', 'Cada palpite pode mudar até o seu jogo começar.'],
    'rodada' => ['Até o 1º jogo da rodada', 'Quando a rodada começa, todos os palpites dela fecham.'],
];

/** Liga em forma de etiqueta: bandeira e nome curto (cabeçalho do bolão, Regras). */
function league_chip(array $league): string
{
    return '<span class="league-chip">' . country_flag_svg((string) ($league['country'] ?? 'OUT'))
        . '<span>' . e(($league['short_name'] ?? '') ?: $league['name']) . '</span></span>';
}

/** Abas de campeonato da aba Palpites (só quando o bolão tem mais de uma liga). */
function render_league_tabs(array $c): void
{
    if (count($c['leagues']) < 2) {
        return;
    }
    ?>
    <nav class="league-tabs" aria-label="Campeonatos">
        <?php foreach ($c['leagues'] as $league):
            $active = (int) $league['id'] === (int) $c['leagueId'];
        ?>
            <a class="league-tab" href="<?= tab_href($c['groupId'], 'palpites', '&amp;league=' . (int) $league['id']) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($league['name']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/** Seletor de rodada pelo nome (útil nas copas, com fases em vez de números). Funciona sem JavaScript. */
function render_round_jump(array $c): void
{
    if (count($c['roundLabels']) < 2) {
        return;
    }
    ?>
    <form method="get" action="group.php" class="round-jump">
        <input type="hidden" name="id" value="<?= (int) $c['groupId'] ?>">
        <input type="hidden" name="tab" value="palpites">
        <input type="hidden" name="league" value="<?= (int) $c['leagueId'] ?>">
        <label class="visually-hidden" for="f-round-jump">Escolher rodada</label>
        <select id="f-round-jump" name="round" data-autosubmit>
            <?php foreach ($c['roundLabels'] as $number => $label): ?>
                <option value="<?= (int) $number ?>"<?= (int) $number === (int) $c['round'] ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn ghost small" data-autosubmit-fallback>Ir</button>
    </form>
    <?php
}
