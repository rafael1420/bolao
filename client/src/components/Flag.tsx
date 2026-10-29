import { memo, useState, type CSSProperties, type ReactNode } from 'react'

const h = (colors: string[]) =>
  colors.map((c, i) => <rect key={i} y={(i * 16) / colors.length} width="24" height={16 / colors.length + 0.05} fill={c} />)
const v = (colors: string[]) => colors.map((c, i) => <rect key={i} x={i * 8} width="8" height="16" fill={c} />)
const globe = (bg: string) => (
  <>
    <rect width="24" height="16" fill={bg} />
    <g fill="none" stroke="#fff" strokeWidth="1.1">
      <circle cx="12" cy="8" r="5.2" />
      <ellipse cx="12" cy="8" rx="2.3" ry="5.2" />
      <path d="M6.8 8h10.4M7.6 5.3h8.8M7.6 10.7h8.8" />
    </g>
  </>
)

const ART: Record<string, () => ReactNode> = {
  BRA: () => (
    <>
      <rect width="24" height="16" fill="#1f9d55" />
      <path d="M12 2.2L21.2 8L12 13.8L2.8 8z" fill="#f7d417" />
      <circle cx="12" cy="8" r="3.4" fill="#22408f" />
    </>
  ),
  ENG: () => (
    <>
      <rect width="24" height="16" fill="#fff" />
      <path d="M10.4 0h3.2v16h-3.2zM0 6.4h24v3.2H0z" fill="#cf2b36" />
    </>
  ),
  SCO: () => (
    <>
      <rect width="24" height="16" fill="#1d5fb4" />
      <path d="M0 0L24 16M24 0L0 16" stroke="#fff" strokeWidth="2.6" />
    </>
  ),
  JPN: () => (
    <>
      <rect width="24" height="16" fill="#fff" />
      <circle cx="12" cy="8" r="4.4" fill="#c8102e" />
    </>
  ),
  ESP: () => (
    <>
      <rect width="24" height="16" fill="#c60b1e" />
      <rect y="4" width="24" height="8" fill="#f6c600" />
    </>
  ),
  POR: () => (
    <>
      <rect width="24" height="16" fill="#da291c" />
      <rect width="9.6" height="16" fill="#046a38" />
      <circle cx="9.6" cy="8" r="2.8" fill="#f6c600" />
    </>
  ),
  USA: () => (
    <>
      {h(['#b22234', '#fff', '#b22234', '#fff', '#b22234', '#fff', '#b22234'])}
      <rect width="10.4" height="8.6" fill="#3c3b6e" />
    </>
  ),
  ITA: () => v(['#009246', '#fff', '#ce2b37']),
  GER: () => h(['#1a1a1a', '#dd0000', '#ffce00']),
  FRA: () => v(['#0055a4', '#fff', '#ef4135']),
  NED: () => h(['#ae1c28', '#fff', '#21468b']),
  BEL: () => v(['#1a1a1a', '#fdda24', '#ef3340']),
  COL: () => (
    <>
      <rect width="24" height="16" fill="#fcd116" />
      <rect y="8" width="24" height="4" fill="#003893" />
      <rect y="12" width="24" height="4" fill="#ce1126" />
    </>
  ),
  ECU: () => (
    <>
      <rect width="24" height="16" fill="#ffdd00" />
      <rect y="8" width="24" height="4" fill="#034ea2" />
      <rect y="12" width="24" height="4" fill="#ed1c24" />
    </>
  ),
  ARG: () => (
    <>
      {h(['#74acdf', '#fff', '#74acdf'])}
      <circle cx="12" cy="8" r="1.8" fill="#f6b40e" />
    </>
  ),
  URU: () => (
    <>
      {h(['#fff', '#0038a8', '#fff', '#0038a8', '#fff', '#0038a8', '#fff', '#0038a8', '#fff'])}
      <rect width="9" height="8.9" fill="#fff" />
      <circle cx="4.5" cy="4.4" r="2.4" fill="#fcd116" />
    </>
  ),
  MEX: () => (
    <>
      {v(['#006847', '#fff', '#ce1126'])}
      <circle cx="12" cy="8" r="2" fill="#8c5a2b" />
    </>
  ),
  CHI: () => (
    <>
      <rect width="24" height="16" fill="#fff" />
      <rect y="8" width="24" height="8" fill="#d52b1e" />
      <rect width="8" height="8" fill="#0039a6" />
      <circle cx="4" cy="4" r="1.6" fill="#fff" />
    </>
  ),
  PAR: () => h(['#d52b1e', '#fff', '#0038a8']),
  PER: () => v(['#d91023', '#fff', '#d91023']),
  TUR: () => (
    <>
      <rect width="24" height="16" fill="#e30a17" />
      <circle cx="9.6" cy="8" r="4" fill="#fff" />
      <circle cx="10.7" cy="8" r="3.2" fill="#e30a17" />
      <circle cx="15" cy="8" r="1.3" fill="#fff" />
    </>
  ),
  KSA: () => (
    <>
      <rect width="24" height="16" fill="#006c35" />
      <rect x="5" y="10.4" width="14" height="1.4" rx=".7" fill="#fff" />
    </>
  ),
  CONMEBOL: () => globe('#2c4f9e'),
  UEFA: () => globe('#20306b'),
  CONCACAF: () => globe('#3d3a8c'),
  FIFA: () => (
    <>
      <rect width="24" height="16" fill="#2b2766" />
      <path
        d="M9 3h6v3a3 3 0 0 1-6 0zM9 4.2H7.4a1.8 1.8 0 0 0 1.8 2.4M15 4.2h1.6a1.8 1.8 0 0 1-1.8 2.4M12 9v2.4M9.6 13h4.8M10.6 11.4h2.8"
        fill="none"
        stroke="#f0d59f"
        strokeWidth="1.1"
        strokeLinecap="round"
      />
    </>
  ),
}

/** Bandeira desenhada (emoji de bandeira não aparece no Windows). Decorativa. */
export const Flag = memo(function Flag({ code, className = 'w-5 h-[13px]', style }: { code: string; className?: string; style?: CSSProperties }) {
  return (
    <svg viewBox="0 0 24 16" className={`${className} shrink-0 rounded-[3px]`} style={style} aria-hidden="true">
      {(ART[code] ?? (() => globe('#5d6088')))()}
      <rect x=".25" y=".25" width="23.5" height="15.5" rx="2" fill="none" stroke="rgba(0,0,0,.18)" strokeWidth=".5" />
    </svg>
  )
})

/** Escudo do campeonato (ESPN); sem escudo ou se a imagem falhar, a bandeira do país. */
export function LeagueMark({ league, size = 20 }: { league: { country: string; logo: string | null; name?: string }; size?: number }) {
  const [failed, setFailed] = useState(false)
  if (!league.logo || failed) return <Flag code={league.country} className="shrink-0" style={{ width: size, height: size * 0.66 }} />
  return (
    <img
      src={league.logo}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      decoding="async"
      referrerPolicy="no-referrer"
      className="shrink-0 object-contain dark:drop-shadow-[0_0_1px_rgba(255,255,255,.6)]"
      style={{ width: size, height: size }}
      onError={() => setFailed(true)}
    />
  )
}
