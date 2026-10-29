// Horários. Os jogos ficam no banco como DATETIME no horário de Brasília (o mesmo da versão anterior),
// e o resto do código trabalha com timestamps Unix em segundos.

export const TZ = 'America/Sao_Paulo'
export const HOUR = 3600
export const DAY = 86400

export const nowTs = () => Math.floor(Date.now() / 1000)

const parts = (ts: number, timeZone: string) => {
  const f = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  })
  const o: Record<string, string> = {}
  for (const p of f.formatToParts(new Date(ts * 1000))) o[p.type] = p.value
  return o
}

/** Timestamp → "2026-10-29 16:00:00" no horário de Brasília. */
export function sqlTime(ts: number, timeZone = TZ) {
  const p = parts(ts, timeZone)
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`
}

/** "2026-10-29" no horário de Brasília. */
export const sqlDate = (ts: number) => sqlTime(ts).slice(0, 10)

/** "2026-10-29 16:00:00" (Brasília) → timestamp. Funciona mesmo se o horário de verão voltar. */
export function fromSql(value: string | Date | null): number {
  if (value === null) return 0
  if (value instanceof Date) return Math.floor(value.getTime() / 1000)
  const [d, t = '00:00:00'] = value.split(/[ T]/)
  const [y, mo, da] = d.split('-').map(Number)
  const [h, mi, s] = t.split(':').map(Number)
  const guess = Date.UTC(y, mo - 1, da, h, mi, s || 0) / 1000
  // Diferença entre o horário de Brasília e UTC naquele instante.
  const shown = parts(guess, TZ)
  const asUtc = Date.UTC(+shown.year, +shown.month - 1, +shown.day, +shown.hour, +shown.minute, +shown.second) / 1000
  return guess - (asUtc - guess)
}

/** Dia no formato da ESPN (YYYYMMDD), que usa o fuso do Pacífico (EUA). */
export function espnDay(ts: number) {
  const p = parts(ts, 'America/Los_Angeles')
  return `${p.year}${p.month}${p.day}`
}

/** Data/hora ISO da ESPN ("2026-10-29T19:00Z") → timestamp. */
export const isoTs = (iso: string | undefined | null) => {
  if (!iso) return NaN
  return Math.floor(Date.parse(iso) / 1000)
}

const DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']

/** "16h" ou "18h30" (Brasília). */
export function clock(ts: number) {
  const p = parts(ts, TZ)
  return p.minute === '00' ? `${Number(p.hour)}h` : `${Number(p.hour)}h${p.minute}`
}

/** "sáb, 16h"; a partir de 6 dias, "02/11, 16h". */
export function dayClock(ts: number, now: number) {
  const p = parts(ts, TZ)
  if (ts - now >= 6 * DAY) return `${p.day}/${p.month}, ${clock(ts)}`
  const wd = new Date(Date.UTC(+p.year, +p.month - 1, +p.day)).getUTCDay()
  return `${DAYS[wd]}, ${clock(ts)}`
}

export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`
