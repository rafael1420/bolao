import { useEffect, useState, type ReactNode, type InputHTMLAttributes } from 'react'
import { Eye, EyeOff, Loader2 } from 'lucide-react'
import { hue, initials } from '../lib/format'

export function Avatar({ name, size = 36, ring = false }: { name: string; size?: number; ring?: boolean }) {
  const h = hue(name)
  return (
    <span
      aria-hidden="true"
      className={`inline-grid place-items-center rounded-full font-bold text-white shrink-0 ${ring ? 'ring-2 ring-accent ring-offset-2 ring-offset-surface' : ''}`}
      style={{
        width: size,
        height: size,
        fontSize: size * 0.38,
        background: `linear-gradient(135deg, hsl(${h} 70% 55%), hsl(${(h + 40) % 360} 65% 40%))`,
      }}
    >
      {initials(name)}
    </span>
  )
}

/** Avatar quadrado do bolão. */
export function GroupMark({ name, size = 48 }: { name: string; size?: number }) {
  const h = hue(name)
  return (
    <span
      aria-hidden="true"
      className="inline-grid place-items-center rounded-2xl font-black text-white shrink-0 display shadow-lg"
      style={{
        width: size,
        height: size,
        fontSize: size * 0.42,
        background: `radial-gradient(circle at 30% 20%, hsl(${h} 80% 66%), hsl(${(h + 30) % 360} 70% 38%))`,
      }}
    >
      {initials(name)}
    </span>
  )
}

export function Spinner({ className = 'size-5' }: { className?: string }) {
  return <Loader2 className={`${className} animate-spin`} aria-hidden="true" />
}

export function Field({
  label,
  error,
  help,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; help?: string }) {
  const [show, setShow] = useState(false)
  const id = props.id ?? `f-${props.name}`
  const isPw = props.type === 'password'
  return (
    <div>
      <label htmlFor={id} className="label">
        {label}
      </label>
      <div className="relative">
        <input
          {...props}
          id={id}
          type={isPw && show ? 'text' : props.type}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-err` : help ? `${id}-help` : undefined}
          className={`input ${isPw ? 'pr-12' : ''} ${error ? '!border-danger focus:!ring-danger-soft' : ''} ${props.className ?? ''}`}
        />
        {isPw && (
          <button
            type="button"
            onClick={() => setShow((s) => !s)}
            className="absolute right-2 top-1/2 -translate-y-1/2 size-9 grid place-items-center rounded-xl text-ink-faint hover:text-ink"
            aria-label={show ? 'Esconder senha' : 'Mostrar senha'}
          >
            {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
          </button>
        )}
      </div>
      {error ? (
        <p id={`${id}-err`} className="mt-1.5 text-[13px] font-medium text-danger">
          {error}
        </p>
      ) : help ? (
        <p id={`${id}-help`} className="mt-1.5 text-[13px] text-ink-faint">
          {help}
        </p>
      ) : null}
    </div>
  )
}

export function Alert({ children, tone = 'danger' }: { children: ReactNode; tone?: 'danger' | 'success' | 'warning' | 'info' }) {
  const styles = {
    danger: 'bg-danger-soft text-danger border-danger/30',
    success: 'bg-success-soft text-success border-success/30',
    warning: 'bg-warning-soft text-warning border-warning/30',
    info: 'bg-accent-soft text-accent-ink border-accent/30',
  }[tone]
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={`rounded-2xl border px-4 py-3 text-sm font-medium ${styles}`}>
      {children}
    </div>
  )
}

export function EmptyState({ icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="card p-8 text-center animate-rise">
      {icon && <div className="mx-auto mb-3 size-12 grid place-items-center rounded-2xl bg-accent-soft text-accent-ink">{icon}</div>}
      <p className="font-semibold">{title}</p>
      {children && <div className="mt-1 text-sm text-ink-soft">{children}</div>}
    </div>
  )
}

export function Skeleton({ className }: { className: string }) {
  return <div className={`skeleton ${className}`} />
}

export function ProgressRing({ value, total, size = 52 }: { value: number; total: number; size?: number }) {
  const r = (size - 6) / 2
  const c = 2 * Math.PI * r
  const p = total ? value / total : 0
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" aria-hidden="true">
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--surface-3)" strokeWidth="5" />
      <circle
        cx={size / 2}
        cy={size / 2}
        r={r}
        fill="none"
        stroke={p >= 1 ? 'var(--success)' : 'var(--accent)'}
        strokeWidth="5"
        strokeLinecap="round"
        strokeDasharray={c}
        strokeDashoffset={c * (1 - p)}
        style={{ transition: 'stroke-dashoffset .6s cubic-bezier(.2,.7,.2,1)' }}
      />
    </svg>
  )
}

/** Hora atual que muda sozinha (prazos e contagens). */
export function useNow(intervalMs = 30000) {
  const [now, setNow] = useState(() => Date.now() / 1000)
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now() / 1000), intervalMs)
    return () => clearInterval(id)
  }, [intervalMs])
  return now
}

export function Segmented<T extends string>({
  value,
  onChange,
  options,
  label,
}: {
  value: T
  onChange: (v: T) => void
  options: [T, string][]
  label: string
}) {
  return (
    <div role="tablist" aria-label={label} className="inline-flex p-1 rounded-2xl bg-surface-2 border border-line gap-1">
      {options.map(([v, text]) => (
        <button
          key={v}
          role="tab"
          aria-selected={value === v}
          onClick={() => onChange(v)}
          className={`px-3.5 h-9 rounded-xl text-sm font-semibold transition ${
            value === v ? 'bg-surface text-ink shadow-card' : 'text-ink-soft hover:text-ink'
          }`}
        >
          {text}
        </button>
      ))}
    </div>
  )
}

export function Logo({ size = 34 }: { size?: number }) {
  return (
    <svg viewBox="0 0 32 32" width={size} height={size} aria-hidden="true" className="shrink-0">
      <defs>
        <linearGradient id="lg-brand" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#3a32a0" />
          <stop offset="1" stopColor="#1e1a5c" />
        </linearGradient>
      </defs>
      <rect width="32" height="32" rx="9" fill="url(#lg-brand)" />
      <path d="M21.2 28.2A12 12 0 0 0 28.2 21.2" fill="none" stroke="#a99cf0" strokeWidth="1.6" strokeLinecap="round" />
      <path d="M15.3 12.3A5.3 5.3 0 1 0 15.3 19.7" fill="none" stroke="#f1eeff" strokeWidth="2.6" strokeLinecap="round" />
      <path
        d="M18.6 10.7V21.3M18.6 10.7H21.5A2.6 2.6 0 0 1 21.5 15.9H18.6M18.6 15.9H22A2.7 2.7 0 0 1 22 21.3H18.6"
        fill="none"
        stroke="#f1eeff"
        strokeWidth="2.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}
