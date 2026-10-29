import { memo, useId, useState } from 'react'
import type { Team } from '../lib/types'
import { hue } from '../lib/format'

function luminance(hex: string) {
  const c = [0, 2, 4].map((i) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
}

/** Cores do escudo a partir das cores da ESPN, ou null quando não servem (iguais, ou ambas claras/escuras). */
function teamColors(c1: string | null, c2: string | null): [string, string, number] | null {
  const ok = (c: string | null): c is string => !!c && /^[0-9a-f]{6}$/i.test(c)
  if (!ok(c1) || !ok(c2)) return null
  let l1 = luminance(c1)
  const l2 = luminance(c2)
  const contrast = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)
  if (contrast < 1.4 || (l1 > 0.8 && l2 > 0.8) || (l1 < 0.02 && l2 < 0.02)) return null
  if (l1 > 0.8) {
    ;[c1, c2, l1] = [c2, c1, l2]
  }
  return [`#${c1}`, `#${c2}`, l1]
}

const SHIELD = 'M20 1.5L37.5 6.8V21c0 11.8-7.6 19.6-17.5 23.5C10.1 40.6 2.5 32.8 2.5 21V6.8z'

/** Escudo desenhado com as cores do time (ou do nome) e a sigla; por cima, o escudo da ESPN quando carrega. */
export const Crest = memo(function Crest({ team, size = 44 }: { team: Team; size?: number }) {
  const clip = useId()
  const [logoOk, setLogoOk] = useState<boolean | null>(team.logo ? null : false)
  const h = hue(team.name)
  const seed = h * 7919
  let main = `hsl(${h} 58% 38%)`
  let second = (seed >> 3) % 2 ? '#f1f4f9' : `hsl(${h} 58% 18%)`
  let pattern = (seed >> 5) % 5
  let text = '#fff'
  const colors = teamColors(team.color, team.color2)
  if (colors) {
    ;[main, second] = colors
    text = colors[2] > 0.45 ? '#1a1b3a' : '#fff'
    if (pattern === 0) pattern = 2
  }
  const letters = team.abbr.slice(0, 3)
  return (
    <span className="relative inline-grid place-items-center shrink-0" style={{ width: size, height: size }} aria-hidden="true">
      {logoOk !== true && (
        <svg viewBox="0 0 40 46" width={size * 0.87} height={size} className="drop-shadow-sm">
          <defs>
            <clipPath id={clip}>
              <path d={SHIELD} />
            </clipPath>
          </defs>
          <g clipPath={`url(#${clip})`}>
            <rect width="40" height="46" fill={main} />
            {pattern === 1 && <rect x="20" width="20" height="46" fill={second} />}
            {pattern === 2 && <rect y="16.5" width="40" height="11" fill={second} />}
            {pattern === 3 && (
              <>
                <rect x="9" width="5.5" height="46" fill={second} />
                <rect x="25.5" width="5.5" height="46" fill={second} />
              </>
            )}
            {pattern === 4 && <path d="M-2 10L10 -2L44 32L32 44z" fill={second} />}
          </g>
          <path d={SHIELD} fill="none" stroke="rgba(255,255,255,.3)" strokeWidth="1.5" />
          <text
            x="20"
            y="24.5"
            textAnchor="middle"
            dominantBaseline="middle"
            fontFamily="'Big Shoulders Display','Arial Narrow',sans-serif"
            fontWeight="800"
            fontSize={letters.length >= 3 ? 13 : 15.5}
            fill={text}
            stroke={text === '#fff' ? 'rgba(0,0,0,.4)' : 'rgba(255,255,255,.55)'}
            strokeWidth="1.6"
            paintOrder="stroke"
          >
            {letters}
          </text>
        </svg>
      )}
      {team.logo && logoOk !== false && (
        <img
          src={team.logo}
          alt=""
          width={size}
          height={size}
          loading="lazy"
          decoding="async"
          referrerPolicy="no-referrer"
          className={logoOk ? "absolute inset-0 w-full h-full object-contain dark:drop-shadow-[0_0_1.5px_rgba(255,255,255,.55)]" : "absolute opacity-0"}
          onLoad={() => setLogoOk(true)}
          onError={() => setLogoOk(false)}
        />
      )}
    </span>
  )
})
