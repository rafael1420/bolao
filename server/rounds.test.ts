// Regras de rodada (rounds.ts). Rodar: npm test
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { canPredict, predictStatus, roundCtx, roundNextDeadline, roundUiState, calculatePoints, roundChipText, type RMatch, type RoundCtx } from './rounds.ts'
import { fromSql } from './time.ts'

const H = 3600
const D = 86400
const now = fromSql('2026-11-04 12:00:00') // quarta-feira

const mk = (id: number, round: number, ts: number, score: [number, number] | null = null, status: string | null = null): RMatch => ({
  id, round, round_label: null, kick: ts, home_score: score?.[0] ?? null, away_score: score?.[1] ?? null, status: status ?? (score ? 'post' : 'pre'),
})

let nextId = 1
const roundOf = (round: number, ts: number, n: number, gap: number, score: [number, number] | null = null) =>
  Array.from({ length: n }, (_, i) => mk(nextId++, round, ts + i * gap, score))
const idsOk = (ctx: RoundCtx, ms: RMatch[]) => ms.filter((m) => canPredict(m, ctx)).map((m) => m.id)
const byId = (ms: RMatch[]) => new Map(ms.map((m) => [m.id, m]))

test('1. rodada normal', () => {
  nextId = 1
  const m = [...roundOf(1, now - 5 * D, 4, 2 * H, [1, 0]), ...roundOf(2, now + 2 * D, 4, 3 * H), ...roundOf(3, now + 9 * D, 4, 3 * H)]
  const ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.equal(ctx.current, 2)
  assert.equal(ctx.next, 3)
  assert.deepEqual(ctx.open, [2])
  assert.deepEqual(idsOk(ctx, m), [5, 6, 7, 8])
  const st = predictStatus(m[8], ctx)
  assert.equal(st.reason, 'locked')
  assert.equal(st.opens_at, now + 2 * D + 9 * H)
  assert.equal(predictStatus(m[0], ctx).reason, 'finished')
  assert.equal(roundUiState(ctx, 1, byId(m)), 'encerrada')
  assert.equal(roundUiState(ctx, 2, byId(m)), 'aberta')
  assert.equal(roundUiState(ctx, 3, byId(m)), 'bloqueada')
})

test('2. jogo adiado 3 semanas', () => {
  nextId = 1
  const m = [
    ...roundOf(2, now + 2 * D, 3, 3 * H),
    mk(nextId++, 2, now + 23 * D),
    ...roundOf(3, now + 9 * D, 4, 3 * H),
    ...roundOf(4, now + 16 * D, 4, 3 * H),
    ...roundOf(5, now + 23 * D - 6 * H, 4, 3 * H),
  ]
  let ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.deepEqual(ctx.rounds.get(2)!.resched, [4])
  assert.equal(ctx.rounds.get(2)!.end, now + 2 * D + 6 * H)
  assert.equal(predictStatus(m[3], ctx).reason, 'locked')
  assert.deepEqual(idsOk(ctx, m), [1, 2, 3])
  const later = now + 22 * D
  const done = m.map((x) => (x.kick < later ? { ...x, home_score: 1, away_score: 1, status: 'post' } : x))
  ctx = roundCtx(done, 'rodada', 'jogo', later)
  assert.equal(ctx.current, 5)
  assert.equal(ctx.rounds.get(2)!.finished, true)
  assert.equal(canPredict(done[3], ctx), true)
})

test('3. jogo adiado sem data (void)', () => {
  nextId = 1
  const m = [...roundOf(1, now - 3 * D, 3, 2 * H, [2, 1]), mk(nextId++, 1, now - 3 * D, null, 'void'), ...roundOf(2, now + 2 * D, 3, 2 * H)]
  const ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.deepEqual(ctx.rounds.get(1)!.ids, [1, 2, 3])
  assert.equal(ctx.rounds.get(1)!.finished, true)
  assert.equal(predictStatus(m[3], ctx).reason, 'void')
  assert.equal(ctx.current, 2)
})

test('4. rodada espalhada em 5 dias', () => {
  nextId = 1
  const m = [mk(nextId++, 7, now - 2 * D, [0, 0]), mk(nextId++, 7, now - D, [1, 0]), mk(nextId++, 7, now + D), mk(nextId++, 7, now + 3 * D), ...roundOf(8, now + 7 * D, 4, 3 * H)]
  const ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.deepEqual(ctx.rounds.get(7)!.core, [1, 2, 3, 4])
  assert.equal(ctx.current, 7)
  assert.deepEqual(ctx.open, [7])
  assert.deepEqual(idsOk(ctx, m), [3, 4])
})

test('5. rodadas seguidas no meio de semana', () => {
  const sunday = fromSql('2026-11-08 15:00:00')
  nextId = 1
  const m = [...roundOf(10, sunday - 26 * H, 3, 2 * H, [1, 1]), ...roundOf(10, sunday + 2 * H, 2, 2 * H), ...roundOf(11, sunday + 52 * H, 4, 2 * H), ...roundOf(12, sunday + 6 * D, 4, 2 * H)]
  let ctx = roundCtx(m, 'rodada', 'jogo', sunday)
  assert.deepEqual(ctx.open, [10])
  ctx = roundCtx(m, 'rodada', 'jogo', sunday + 5 * H)
  assert.deepEqual(ctx.open, [10, 11])
  assert.equal(predictStatus(m[9], ctx).reason, 'locked')
  assert.equal(predictStatus(m[9], ctx).after, 11)
})

test('6. jogo travado sem placar', () => {
  nextId = 1
  const m = [...roundOf(20, now - 4 * D, 3, 2 * H, [2, 2]), mk(nextId++, 20, now - 4 * D + 7 * H), ...roundOf(21, now + 2 * D, 3, 2 * H)]
  const ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.equal(ctx.rounds.get(20)!.finished, true)
  assert.equal(ctx.current, 21)
  assert.equal(predictStatus(m[3], ctx).reason, 'started')
})

test('7. fim de temporada', () => {
  nextId = 1
  const m = [...roundOf(37, now - 9 * D, 3, H, [1, 0]), ...roundOf(38, now - 2 * D, 3, H, [0, 2])]
  const ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.equal(ctx.over, true)
  assert.equal(ctx.current, 38)
  assert.deepEqual(ctx.open, [])
})

test('8. os três modos e o prazo por rodada', () => {
  nextId = 1
  const m = [...roundOf(1, now + D, 3, 3 * H), ...roundOf(2, now + 8 * D, 3, 3 * H), ...roundOf(3, now + 15 * D, 3, 3 * H)]
  let ctx = roundCtx(m, 'duas', 'jogo', now)
  assert.deepEqual(ctx.open, [1, 2])
  assert.equal(predictStatus(m[6], ctx).reason, 'locked')
  ctx = roundCtx(m, 'duas', 'jogo', now + D + 7 * H)
  assert.deepEqual(ctx.open, [1, 2, 3])
  ctx = roundCtx(m, 'livre', 'jogo', now)
  assert.deepEqual(idsOk(ctx, m), [1, 2, 3, 4, 5, 6, 7, 8, 9])
  ctx = roundCtx(m, 'rodada', 'jogo', now)
  assert.deepEqual(idsOk(ctx, m), [1, 2, 3])
  const mid = now + D + H
  ctx = roundCtx(m, 'rodada', 'rodada', mid)
  assert.equal(predictStatus(m[1], ctx).reason, 'deadline')
  ctx = roundCtx(m, 'rodada', 'jogo', mid)
  assert.equal(canPredict(m[1], ctx), true)
  ctx = roundCtx(m, 'rodada', 'rodada', now)
  assert.equal(roundNextDeadline(ctx, 1, byId(m)), now + D)
})

test('pontuação e nomes curtos', () => {
  const r = { exact: 5, winner: 3, diff: 1 }
  assert.equal(calculatePoints(2, 1, 2, 1, r), 5)
  assert.equal(calculatePoints(2, 1, 3, 2, r), 4)
  assert.equal(calculatePoints(2, 0, 3, 2, r), 3)
  assert.equal(calculatePoints(1, 1, 0, 0, r), 4)
  assert.equal(calculatePoints(0, 1, 2, 0, r), 0)
  assert.equal(roundChipText('Oitavas de final · ida'), 'OIT ida')
  assert.equal(roundChipText('Fase de liga · Rodada 3'), 'FL 3')
  assert.equal(roundChipText('3ª fase'), '3ª')
})
