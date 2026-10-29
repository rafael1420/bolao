import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, ChevronDown, Search } from 'lucide-react'
import { apiGet } from '../lib/api'
import type { PickerCountry } from '../lib/types'
import { plural } from '../lib/format'
import { Flag, LeagueMark } from './Flag'
import { Skeleton } from './ui'

const OPEN = ['BRA', 'CONMEBOL', 'UEFA']
const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/** Seletor de campeonatos por país, com busca e limite de ligas. */
export function LeaguePicker({ value, onChange, initial }: { value: number[]; onChange: (ids: number[]) => void; initial: number[] }) {
  const q = useQuery({
    queryKey: ['leagues', initial.join(',')],
    queryFn: () => apiGet<{ countries: PickerCountry[]; max: number }>('leagues', { selected: initial.join(',') }),
    staleTime: 10 * 60_000,
  })
  const [search, setSearch] = useState('')
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const max = q.data?.max ?? 6
  const s = norm(search.trim())

  const countries = useMemo(() => {
    if (!q.data) return []
    return q.data.countries
      .map((c) => ({ ...c, leagues: s ? c.leagues.filter((l) => norm(`${l.name} ${l.short} ${c.name}`).includes(s)) : c.leagues }))
      .filter((c) => c.leagues.length)
  }, [q.data, s])

  if (q.isLoading) return <Skeleton className="h-64" />

  const toggle = (id: number) => {
    if (value.includes(id)) onChange(value.filter((x) => x !== id))
    else if (value.length < max) onChange([...value, id])
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-ink-soft">Escolha até {max} campeonatos.</p>
        <span className={`chip ${value.length ? 'bg-accent-soft text-accent-ink border-accent/30' : ''}`} aria-live="polite">
          {plural(value.length, 'selecionado', 'selecionados')}
        </span>
      </div>
      <div className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-4 text-ink-faint" />
        <input type="search" className="input pl-11" placeholder="Buscar: Premier League, Copa do Brasil…" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Buscar campeonato" />
      </div>
      <div className="space-y-2">
        {countries.map((c) => {
          const count = c.leagues.filter((l) => value.includes(l.id)).length
          const isOpen = s !== '' || (expanded[c.code] ?? (OPEN.includes(c.code) || count > 0))
          return (
            <div key={c.code} className="rounded-2xl border border-line bg-surface-2/50 overflow-hidden">
              <button
                type="button"
                onClick={() => setExpanded((e) => ({ ...e, [c.code]: !isOpen }))}
                aria-expanded={isOpen}
                className="w-full flex items-center gap-3 px-4 h-12 text-left font-semibold hover:bg-surface-2"
              >
                <Flag code={c.code} className="w-6 h-4" />
                <span className="flex-1">{c.name}</span>
                {count > 0 && <span className="chip h-6 bg-accent-soft text-accent-ink border-accent/30">{count}</span>}
                <ChevronDown className={`size-4 text-ink-faint transition ${isOpen ? 'rotate-180' : ''}`} />
              </button>
              {isOpen && (
                <div className="grid gap-2 p-2 pt-0 sm:grid-cols-2">
                  {c.leagues.map((l) => {
                    const on = value.includes(l.id)
                    const disabled = !on && value.length >= max
                    return (
                      <button
                        type="button"
                        key={l.id}
                        role="checkbox"
                        aria-checked={on}
                        disabled={disabled}
                        onClick={() => toggle(l.id)}
                        className={`flex items-center gap-3 rounded-xl border p-3 text-left transition active:scale-[0.98] ${
                          on ? 'border-accent bg-accent-soft' : 'border-line bg-surface hover:border-line-strong'
                        } disabled:opacity-40`}
                      >
                        <span className={`size-5 shrink-0 grid place-items-center rounded-md border-2 transition ${on ? 'bg-accent border-accent text-white' : 'border-line-strong'}`}>
                          {on && <Check className="size-3.5" strokeWidth={3} />}
                        </span>
                        <span className="flex-1 min-w-0">
                          <span className="block text-sm font-semibold truncate">{l.name}</span>
                          <span className="flex gap-1.5 mt-0.5 text-[11px] text-ink-faint">
                            <span>{l.kind === 'copa' ? 'Copa' : 'Liga'}</span>
                            {l.season && <span>· {l.season}</span>}
                            {l.offSeason && <span className="text-warning">· fora de temporada</span>}
                          </span>
                        </span>
                        <LeagueMark league={{ country: c.code, logo: l.logo }} size={28} />
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )
        })}
        {!countries.length && <p className="text-sm text-ink-faint text-center py-6">Nenhum campeonato encontrado.</p>}
      </div>
    </div>
  )
}

export function PointsInput({ label, value, onChange }: { label: string; value: number; onChange: (n: number) => void }) {
  return (
    <div className="rounded-2xl border border-line bg-surface-2 p-3 text-center">
      <p className="text-xs font-semibold text-ink-soft mb-2 min-h-8 grid place-items-center">{label}</p>
      <div className="flex items-center justify-center gap-1">
        <button type="button" onClick={() => onChange(Math.max(0, value - 1))} className="size-8 rounded-lg bg-surface border border-line font-bold" aria-label={`Menos um: ${label}`}>−</button>
        <input
          inputMode="numeric"
          aria-label={label}
          value={value}
          onChange={(e) => {
            const n = Number(e.target.value.replace(/\D/g, '') || 0)
            onChange(Math.min(20, n))
          }}
          className="w-12 text-center display text-3xl font-black bg-transparent outline-none tabular"
        />
        <button type="button" onClick={() => onChange(Math.min(20, value + 1))} className="size-8 rounded-lg bg-surface border border-line font-bold" aria-label={`Mais um: ${label}`}>+</button>
      </div>
    </div>
  )
}
