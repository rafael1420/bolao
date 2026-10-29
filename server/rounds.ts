// Regras das rodadas: qual é a rodada atual, quais aceitam palpite e por quê.
// Funções puras (recebem jogos e o horário), testadas em rounds.test.ts. Horários em segundos Unix.
import { DAY, HOUR, dayClock } from './time.ts'

export const ROUND_CORE_WINDOW = 4 * DAY // jogo a mais de 4 dias da mediana da rodada é remarcado
export const ROUND_STUCK_AFTER = 3 * DAY // jogo sem placar há mais de 3 dias não prende a rodada
export const ROUND_EARLY_OPEN = 48 * HOUR // a próxima rodada abre 48 h antes do primeiro jogo dela
export const ROUND_LATE_MARGIN = 24 * HOUR // remarcado abre se for até 24 h depois da última rodada aberta

export type RoundMode = 'rodada' | 'duas' | 'livre'
export type DeadlineMode = 'jogo' | 'rodada'

/** O mínimo de um jogo para as regras. */
export type RMatch = {
  id: number
  round: number
  round_label?: string | null
  kick: number
  home_score: number | null
  away_score?: number | null
  status: string | null
}

export type Round = {
  number: number
  label: string
  ids: number[]
  core: number[]
  resched: number[]
  start: number | null
  end: number | null
  last_kick: number
  finished: boolean
  all_started: boolean
}

export type LeagueRounds = {
  rounds: Map<number, Round>
  by_start: number[]
  numbers: number[]
  current: number | null
  next: number | null
  over: boolean
}

export type RoundCtx = LeagueRounds & {
  mode: RoundMode
  deadline: DeadlineMode
  open: number[]
  horizon: number | null
  /** jogo → [rodada, remarcado?] */
  where: Map<number, [number, boolean]>
  now: number
}

const started = (m: RMatch) => m.status === 'in' || m.status === 'post'

/**
 * Rodadas de uma liga (adiados e cancelados ficam de fora). Núcleo: jogos a até 4 dias da mediana da
 * rodada; os demais são remarcados. Encerrada: todo jogo do núcleo tem placar ou começou há mais de 3 dias.
 * A rodada atual é a que começa primeiro entre as que não acabaram.
 */
export function leagueRounds(matches: RMatch[], now: number): LeagueRounds {
  const groups = new Map<number, RMatch[]>()
  for (const m of matches) {
    if (m.status === 'void') continue
    const list = groups.get(m.round) ?? []
    list.push(m)
    groups.set(m.round, list)
  }
  const rounds = new Map<number, Round>()
  for (const number of [...groups.keys()].sort((a, b) => a - b)) {
    const list = groups.get(number)!
    const sorted = list.map((m) => m.kick).sort((a, b) => a - b)
    const median = sorted[Math.floor((sorted.length - 1) / 2)]
    const core: number[] = []
    const resched: number[] = []
    let start: number | null = null
    let end: number | null = null
    let finished = true
    let allStarted = true
    let label: string | null = null
    for (const m of list) {
      label = label ?? (m.round_label || null)
      if (Math.abs(m.kick - median) <= ROUND_CORE_WINDOW) {
        core.push(m.id)
        start = start === null ? m.kick : Math.min(start, m.kick)
        end = end === null ? m.kick : Math.max(end, m.kick)
        const hasScore = m.home_score !== null && m.home_score !== undefined
        if (!hasScore && m.kick > now - ROUND_STUCK_AFTER) finished = false
        if (m.kick > now && !hasScore && !started(m)) allStarted = false
      } else {
        resched.push(m.id)
      }
    }
    rounds.set(number, {
      number,
      label: label || `Rodada ${number}`,
      ids: list.map((m) => m.id),
      core,
      resched,
      start,
      end,
      last_kick: Math.max(...list.map((m) => m.kick)),
      finished,
      all_started: allStarted,
    })
  }
  const byStart = [...rounds.keys()].sort((a, b) => {
    const sa = rounds.get(a)!.start ?? 0
    const sb = rounds.get(b)!.start ?? 0
    return sa - sb || a - b
  })
  let current: number | null = null
  for (const n of byStart) {
    if (!rounds.get(n)!.finished) {
      current = n
      break
    }
  }
  const over = current === null && rounds.size > 0
  if (over) current = Math.max(...rounds.keys())
  let next: number | null = null
  if (current !== null && !over) next = byStart[byStart.indexOf(current) + 1] ?? null
  return { rounds, by_start: byStart, numbers: [...rounds.keys()], current, next, over }
}

/** Rodadas abertas conforme o modo do bolão. Temporada encerrada: nenhuma. */
export function openRounds(lr: LeagueRounds, mode: RoundMode, now: number): number[] {
  if (lr.over || lr.current === null) return []
  if (mode === 'livre') return [...lr.numbers]
  const c = lr.current
  const n = lr.next
  const open = [c]
  if (n === null) return open
  const cur = lr.rounds.get(c)!
  if (mode === 'duas') {
    open.push(n)
    const after = lr.by_start[lr.by_start.indexOf(n) + 1]
    if (after !== undefined && cur.all_started) open.push(after)
    return open
  }
  if (cur.all_started || (lr.rounds.get(n)!.start ?? Infinity) <= now + ROUND_EARLY_OPEN) open.push(n)
  return open
}

/** Quando uma rodada bloqueada abre: [horário ou null, rodada que precisa acabar antes ou null]. */
export function roundOpensAt(lr: LeagueRounds, number: number, mode: RoundMode): [number | null, number | null] {
  const c = lr.current
  const n = lr.next
  if (c === null || lr.over || mode === 'livre') return [null, null]
  const pos = lr.by_start.indexOf(number)
  const before = pos > 0 ? lr.by_start[pos - 1] : null
  const cur = lr.rounds.get(c)!
  if (mode === 'rodada' && number === n) {
    return [Math.min(cur.end ?? Infinity, (lr.rounds.get(n)!.start ?? Infinity) - ROUND_EARLY_OPEN), c]
  }
  if (mode === 'duas' && n !== null && pos === lr.by_start.indexOf(n) + 1) return [cur.end, c]
  return [null, before]
}

/** Contexto das rodadas de uma liga para um bolão: rodadas, abertas, prazo e limite dos remarcados. */
export function roundCtx(matches: RMatch[], mode: string, deadline: string, now: number): RoundCtx {
  const m: RoundMode = mode === 'duas' || mode === 'livre' ? mode : 'rodada'
  const d: DeadlineMode = deadline === 'rodada' ? 'rodada' : 'jogo'
  const lr = leagueRounds(matches, now)
  const open = openRounds(lr, m, now)
  let horizon: number | null = null
  for (const n of open) {
    const end = lr.rounds.get(n)!.end
    if (end !== null) horizon = horizon === null ? end : Math.max(horizon, end)
  }
  if (horizon !== null) horizon += ROUND_LATE_MARGIN
  const where = new Map<number, [number, boolean]>()
  for (const [number, r] of lr.rounds) {
    for (const id of r.resched) where.set(id, [number, true])
    for (const id of r.core) where.set(id, [number, false])
  }
  return { ...lr, mode: m, deadline: d, open, horizon, where, now }
}

export type PredictReason = null | 'finished' | 'started' | 'void' | 'locked' | 'deadline'
export type PredictStatus = { ok: boolean; reason: PredictReason; opens_at: number | null; after: number | null }

/** Pode palpitar neste jogo agora? */
export function predictStatus(m: RMatch, ctx: RoundCtx): PredictStatus {
  const out: PredictStatus = { ok: false, reason: null, opens_at: null, after: null }
  if (m.status === 'void') return { ...out, reason: 'void' }
  if (m.home_score !== null && m.home_score !== undefined) return { ...out, reason: 'finished' }
  if (started(m) || m.kick <= ctx.now) return { ...out, reason: 'started' }
  if (ctx.mode === 'livre') return { ...out, ok: true }
  const [number, resched] = ctx.where.get(m.id) ?? [m.round, false]
  const round = ctx.rounds.get(number)
  if (resched) {
    // Remarcado: vale a semana em que vai ser disputado, não a rodada de origem.
    if (ctx.horizon !== null && m.kick <= ctx.horizon) return { ...out, ok: true }
    return { ...out, reason: 'locked' }
  }
  if (!ctx.open.includes(number)) {
    const [at, after] = roundOpensAt(ctx, number, ctx.mode)
    return { ...out, reason: 'locked', opens_at: at, after }
  }
  if (ctx.deadline === 'rodada' && round && round.start !== null && round.start <= ctx.now) return { ...out, reason: 'deadline' }
  return { ...out, ok: true }
}

export const canPredict = (m: RMatch, ctx: RoundCtx) => predictStatus(m, ctx).ok

/** Motivo, em texto, de um palpite recusado. */
export function predictReasonText(s: PredictStatus, ctx: RoundCtx, now: number) {
  switch (s.reason) {
    case 'finished':
      return 'o jogo já terminou'
    case 'started':
      return 'o jogo já começou'
    case 'void':
      return 'o jogo foi adiado ou cancelado'
    case 'deadline':
      return 'o prazo da rodada já passou'
    case 'locked':
      if (s.opens_at !== null) return `a rodada ainda não abriu (abre ${dayClock(s.opens_at, now)})`
      if (s.after !== null && ctx.rounds.has(s.after)) return `a rodada ainda não abriu (abre depois da ${ctx.rounds.get(s.after)!.label.toLowerCase()})`
      return 'a rodada ainda não abriu'
  }
  return 'palpite não aceito'
}

export type RoundUiState = 'encerrada' | 'aguardando' | 'aberta' | 'bloqueada'

/** Estado da rodada na tela. */
export function roundUiState(ctx: RoundCtx, number: number, byId: Map<number, RMatch>): RoundUiState {
  const r = ctx.rounds.get(number)
  if (!r) return 'bloqueada'
  if (r.finished) return 'encerrada'
  for (const id of r.ids) {
    const m = byId.get(id)
    if (m && canPredict(m, ctx)) return 'aberta'
  }
  if (r.all_started || ctx.open.includes(number) || ctx.mode === 'livre') return 'aguardando'
  return 'bloqueada'
}

/** Prazo da rodada para o cabeçalho: com prazo "rodada", o primeiro jogo do núcleo; senão o próximo jogo aberto. */
export function roundNextDeadline(ctx: RoundCtx, number: number, byId: Map<number, RMatch>): number | null {
  const r = ctx.rounds.get(number)
  if (!r) return null
  let next: number | null = null
  for (const id of r.ids) {
    const m = byId.get(id)
    if (m && canPredict(m, ctx)) next = next === null ? m.kick : Math.min(next, m.kick)
  }
  if (next !== null && ctx.deadline === 'rodada' && r.start !== null && r.start > ctx.now) return r.start
  return next
}

/** "Abre em qui, 19h" ou "Abre depois da Rodada 12". */
export function roundLockedText(ctx: RoundCtx, number: number, now: number) {
  const [at, after] = roundOpensAt(ctx, number, ctx.mode)
  if (at !== null && at > now) return `Abre em ${dayClock(at, now)}`
  if (after !== null && ctx.rounds.has(after)) return `Abre depois da ${ctx.rounds.get(after)!.label}`
  return 'Ainda fechada para palpites'
}

/** Texto curto do chip nas copas: "Oitavas de final · ida" vira "OIT ida". */
export function roundChipText(label: string) {
  const map: Record<string, string> = {
    'Fase de liga': 'FL', 'Fase de grupos': 'G', Playoffs: 'PO', '16 avos de final': '16', 'Oitavas de final': 'OIT',
    'Quartas de final': 'QF', Semifinal: 'SF', Final: 'F', 'Fase preliminar': 'PR',
  }
  const [first, tail = ''] = label.split(' · ')
  const fase = first.match(/^(\d+)ª fase/)
  const head = map[first] ?? (fase ? `${fase[1]}ª` : first.slice(0, 3))
  const r = tail.match(/^Rodada (\d+)$/)
  return `${head} ${r ? r[1] : tail}`.trim()
}

/** Pontos de um palpite: exato vale só o exato; vencedor/empate; +saldo. */
export function calculatePoints(ph: number, pa: number, h: number, a: number, rules: { exact: number; winner: number; diff: number }) {
  if (ph === h && pa === a) return rules.exact
  if (Math.sign(ph - pa) !== Math.sign(h - a)) return 0
  return rules.winner + (ph - pa === h - a ? rules.diff : 0)
}

export function pointsReason(ph: number, pa: number, h: number, a: number) {
  if (ph === h && pa === a) return 'Placar exato'
  if (Math.sign(ph - pa) !== Math.sign(h - a)) return 'Nenhum acerto'
  if (ph - pa === h - a) return 'Vencedor e saldo'
  return 'Vencedor ou empate'
}
