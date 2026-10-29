<?php
// Testes das regras de rodada (includes/rounds.php), sem banco e sem framework.
// Rodar: php tests/rounds_test.php  (sai com código 1 se algum falhar)

date_default_timezone_set('America/Sao_Paulo');
require __DIR__ . '/../includes/rounds.php';

$failures = 0;
$checks = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  ok   $name\n";
        return;
    }
    $failures++;
    echo "  FALHOU $name\n         esperado: " . var_export($expected, true) . "\n         obtido:   " . var_export($actual, true) . "\n";
}

/** Jogo de teste: id, rodada, horário, placar (null = sem placar) e status da ESPN. */
function mk(int $id, int $round, int $ts, ?array $score = null, ?string $status = null): array
{
    return [
        'id' => $id,
        'round' => $round,
        'round_label' => null,
        'kickoff_at' => date('Y-m-d H:i:s', $ts),
        'home_score' => $score[0] ?? null,
        'away_score' => $score[1] ?? null,
        'status' => $status ?? ($score ? 'post' : 'pre'),
    ];
}

/** Rodada de $n jogos começando em $ts, um a cada $gap segundos. */
function round_of(int &$id, int $round, int $ts, int $n, int $gap, ?array $score = null): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = mk($id++, $round, $ts + $i * $gap, $score);
    }
    return $out;
}

function ids_ok(array $ctx, array $matches): array
{
    $ok = [];
    foreach ($matches as $m) {
        if (can_predict($m, $ctx)) {
            $ok[] = $m['id'];
        }
    }
    return $ok;
}

$H = 3600;
$D = 86400;
$now = strtotime('2026-11-04 12:00:00'); // quarta-feira

echo "1. Rodada normal\n";
$id = 1;
$m = array_merge(
    round_of($id, 1, $now - 5 * $D, 4, 2 * $H, [1, 0]),  // encerrada
    round_of($id, 2, $now + 2 * $D, 4, 3 * $H),          // sexta e sábado
    round_of($id, 3, $now + 9 * $D, 4, 3 * $H)           // semana seguinte
);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('rodada atual é a 2', 2, $ctx['current']);
check('próxima é a 3', 3, $ctx['next']);
check('só a 2 aberta', [2], $ctx['open']);
check('palpitáveis: jogos da 2', [5, 6, 7, 8], ids_ok($ctx, $m));
$st = predict_status($m[8], $ctx);
check('jogo da 3 bloqueado', 'locked', $st['reason']);
check('rodada 3 abre no último jogo da 2', $now + 2 * $D + 9 * $H, $st['opens_at']);
check('jogo da 1 encerrado', 'finished', predict_status($m[0], $ctx)['reason']);
check('estado da 1', 'encerrada', round_ui_state($ctx, 1, array_column($m, null, 'id')));
check('estado da 2', 'aberta', round_ui_state($ctx, 2, array_column($m, null, 'id')));
check('estado da 3', 'bloqueada', round_ui_state($ctx, 3, array_column($m, null, 'id')));

echo "2. Jogo adiado 3 semanas\n";
$id = 1;
$m = array_merge(
    round_of($id, 2, $now + 2 * $D, 3, 3 * $H),
    [mk($id++, 2, $now + 23 * $D)],                       // remarcado para daqui a 3 semanas
    round_of($id, 3, $now + 9 * $D, 4, 3 * $H),
    round_of($id, 4, $now + 16 * $D, 4, 3 * $H),
    round_of($id, 5, $now + 23 * $D - 6 * $H, 4, 3 * $H)
);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('remarcado não está no núcleo da 2', [4], $ctx['rounds'][2]['resched']);
check('fim da 2 ignora o remarcado', $now + 2 * $D + 6 * $H, $ctx['rounds'][2]['end']);
check('remarcado ainda fechado', 'locked', predict_status($m[3], $ctx)['reason']);
check('palpitáveis agora: núcleo da 2', [1, 2, 3], ids_ok($ctx, $m));
// Três semanas depois: a rodada 5 é a atual e o remarcado da 2 abre junto com ela.
$later = $now + 22 * $D;
$done = array_map(function ($x) use ($later) {
    return strtotime($x['kickoff_at']) < $later ? array_merge($x, ['home_score' => 1, 'away_score' => 1, 'status' => 'post']) : $x;
}, $m);
$ctx = round_ctx_build($done, 'rodada', 'jogo', $later);
check('três semanas depois: atual é a 5', 5, $ctx['current']);
check('rodada 2 já encerrada (núcleo)', true, $ctx['rounds'][2]['finished']);
check('remarcado da 2 aberto na semana dele', true, can_predict($done[3], $ctx));

echo "3. Jogo adiado sem data (void)\n";
$id = 1;
$m = array_merge(round_of($id, 1, $now - 3 * $D, 3, 2 * $H, [2, 1]), [mk($id++, 1, $now - 3 * $D, null, 'void')], round_of($id, 2, $now + 2 * $D, 3, 2 * $H));
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('void fora da rodada', [1, 2, 3], $ctx['rounds'][1]['ids']);
check('rodada 1 encerrada mesmo com o void', true, $ctx['rounds'][1]['finished']);
check('void não aceita palpite', 'void', predict_status($m[3], $ctx)['reason']);
check('atual é a 2', 2, $ctx['current']);

echo "4. Rodada espalhada em 5 dias\n";
$id = 1;
$m = [
    mk($id++, 7, $now - 2 * $D, [0, 0]), mk($id++, 7, $now - 1 * $D, [1, 0]),
    mk($id++, 7, $now + 1 * $D), mk($id++, 7, $now + 3 * $D),
];
$m = array_merge($m, round_of($id, 8, $now + 7 * $D, 4, 3 * $H));
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('todos no núcleo', [1, 2, 3, 4], $ctx['rounds'][7]['core']);
check('rodada 7 continua atual', 7, $ctx['current']);
check('só a 7 aberta', [7], $ctx['open']);
check('palpitáveis: os 2 jogos que faltam da 7', [3, 4], ids_ok($ctx, $m));

echo "5. Rodadas seguidas no meio de semana\n";
$sunday = strtotime('2026-11-08 15:00:00');
$id = 1;
$m = array_merge(
    round_of($id, 10, $sunday - 26 * $H, 3, 2 * $H, [1, 1]), // sábado, encerrados
    round_of($id, 10, $sunday + 2 * $H, 2, 2 * $H),           // domingo, mais tarde
    round_of($id, 11, $sunday + 52 * $H, 4, 2 * $H),          // terça à noite
    round_of($id, 12, $sunday + 6 * $D, 4, 2 * $H)            // sábado seguinte
);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $sunday);
check('48 h antes ainda não: só a 10', [10], $ctx['open']);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $sunday + 5 * $H);
check('domingo à noite: 10 e 11 abertas', [10, 11], $ctx['open']);
check('a 12 continua bloqueada', 'locked', predict_status($m[9], $ctx)['reason']);
check('a 12 abre depois da 11', 11, predict_status($m[9], $ctx)['after']);

echo "6. Jogo travado sem placar\n";
$id = 1;
$m = array_merge(
    round_of($id, 20, $now - 4 * $D, 3, 2 * $H, [2, 2]),
    [mk($id++, 20, $now - 4 * $D + 7 * $H)],               // começou há 4 dias, nunca veio placar
    round_of($id, 21, $now + 2 * $D, 3, 2 * $H)
);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('rodada 20 encerrada pela regra dos 3 dias', true, $ctx['rounds'][20]['finished']);
check('atual é a 21', 21, $ctx['current']);
check('jogo travado não aceita palpite', 'started', predict_status($m[3], $ctx)['reason']);

echo "7. Fim de temporada\n";
$id = 1;
$m = array_merge(round_of($id, 37, $now - 9 * $D, 3, $H, [1, 0]), round_of($id, 38, $now - 2 * $D, 3, $H, [0, 2]));
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('temporada encerrada', true, $ctx['over']);
check('atual é a última', 38, $ctx['current']);
check('nada aberto', [], $ctx['open']);

echo "8. Os três modos e o prazo por rodada\n";
$id = 1;
$m = array_merge(
    round_of($id, 1, $now + 1 * $D, 3, 3 * $H),
    round_of($id, 2, $now + 8 * $D, 3, 3 * $H),
    round_of($id, 3, $now + 15 * $D, 3, 3 * $H)
);
$ctx = round_ctx_build($m, 'duas', 'jogo', $now);
check('duas: atual e próxima', [1, 2], $ctx['open']);
check('duas: 3 bloqueada', 'locked', predict_status($m[6], $ctx)['reason']);
$ctx = round_ctx_build($m, 'duas', 'jogo', $now + 1 * $D + 7 * $H); // toda a 1 começou
check('duas: com a atual toda começada, abre a 3', [1, 2, 3], $ctx['open']);
$ctx = round_ctx_build($m, 'livre', 'jogo', $now);
check('livre: tudo aberto', [1, 2, 3, 4, 5, 6, 7, 8, 9], ids_ok($ctx, $m));
$ctx = round_ctx_build($m, 'rodada', 'jogo', $now);
check('rodada: só a 1', [1, 2, 3], ids_ok($ctx, $m));
// Prazo "rodada": depois do 1º jogo, o resto da rodada fecha também.
$mid = $now + 1 * $D + 1 * $H;
$ctx = round_ctx_build($m, 'rodada', 'rodada', $mid);
check('prazo rodada: 2º jogo fechado', 'deadline', predict_status($m[1], $ctx)['reason']);
$ctx = round_ctx_build($m, 'rodada', 'jogo', $mid);
check('prazo jogo: 2º jogo aberto', true, can_predict($m[1], $ctx));
$ctx = round_ctx_build($m, 'rodada', 'rodada', $now);
check('prazo rodada: cabeçalho mostra o 1º jogo', $now + 1 * $D, round_next_deadline($ctx, 1, array_column($m, null, 'id')));

echo "\n" . ($checks - $failures) . " de $checks verificações passaram.\n";
exit($failures ? 1 : 0);
