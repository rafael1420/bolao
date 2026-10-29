// Datas e textos no jeito da casa: "sáb, 16h", "em 2 h 14 min", "3 pontos".

const DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']

export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

export function clock(ts: number) {
  const d = new Date(ts * 1000)
  const m = d.getMinutes()
  return m === 0 ? `${d.getHours()}h` : `${d.getHours()}h${String(m).padStart(2, '0')}`
}

export function dayMonth(ts: number) {
  const d = new Date(ts * 1000)
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** "sáb, 16h"; a partir de 6 dias, "02/11, 16h". */
export function dayClock(ts: number, now = Date.now() / 1000) {
  const d = new Date(ts * 1000)
  if (ts - now >= 6 * 86400) return `${dayMonth(ts)}, ${clock(ts)}`
  return `${DAYS[d.getDay()]}, ${clock(ts)}`
}

/** Tempo até o prazo: "em 2 h 14 min", "em 12 min", "sáb, 16h". */
export function countdown(ts: number, now = Date.now() / 1000) {
  const diff = Math.floor(ts - now)
  if (diff <= 0) return `fechou às ${clock(ts)}`
  if (diff >= 86400) return dayClock(ts, now)
  if (diff >= 3600) {
    const h = Math.floor(diff / 3600)
    const m = Math.floor((diff % 3600) / 60)
    return `em ${h} h${m ? ` ${m} min` : ''}`
  }
  if (diff >= 60) return `em ${Math.floor(diff / 60)} min`
  return 'em menos de 1 min'
}

export function dayTitle(isoDay: string, today: string) {
  const [y, m, d] = isoDay.split('-').map(Number)
  const date = new Date(y, m - 1, d, 12)
  const [ty, tm, td] = today.split('-').map(Number)
  const diff = Math.round((date.getTime() - new Date(ty, tm - 1, td, 12).getTime()) / 86400000)
  if (diff === 0) return 'Hoje'
  if (diff === 1) return 'Amanhã'
  if (diff === -1) return 'Ontem'
  return `${DAYS[date.getDay()]}, ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}`
}

export const firstName = (name: string) => name.trim().split(/\s+/)[0] || name

export function initials(name: string) {
  const w = name.trim().split(/\s+/).filter(Boolean)
  if (!w.length) return '?'
  if (w.length === 1) return w[0].slice(0, 2).toUpperCase()
  return (w[0][0] + w[w.length - 1][0]).toUpperCase()
}

/** Matiz fixo pelo nome (avatares). */
export function hue(text: string) {
  let h = 0
  for (const ch of text.toLowerCase().trim()) h = (h * 31 + ch.charCodeAt(0)) >>> 0
  return h % 360
}

/** Pontuação do exemplo da tela de regras: palpite 2×1, resultado 3×2. */
export function pointsFor(ph: number, pa: number, h: number, a: number, r: { exact: number; winner: number; diff: number }) {
  if (ph === h && pa === a) return r.exact
  if (Math.sign(ph - pa) !== Math.sign(h - a)) return 0
  return r.winner + (ph - pa === h - a ? r.diff : 0)
}
