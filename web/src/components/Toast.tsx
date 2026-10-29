import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-react'

type Tone = 'success' | 'error' | 'info'
type Toast = { id: number; text: string; tone: Tone }

const Ctx = createContext<(text: string, tone?: Tone) => void>(() => {})
export const useToast = () => useContext(Ctx)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [list, setList] = useState<Toast[]>([])
  const push = useCallback((text: string, tone: Tone = 'success') => {
    const id = Date.now() + Math.random()
    setList((l) => [...l.slice(-2), { id, text, tone }])
    setTimeout(() => setList((l) => l.filter((t) => t.id !== id)), tone === 'error' ? 6000 : 3500)
  }, [])
  return (
    <Ctx.Provider value={push}>
      {children}
      <div
        aria-live="polite"
        className="fixed z-[60] left-1/2 -translate-x-1/2 bottom-[calc(84px+env(safe-area-inset-bottom))] md:bottom-6 flex flex-col gap-2 w-[min(92vw,420px)] pointer-events-none"
      >
        {list.map((t) => {
          const Icon = t.tone === 'success' ? CheckCircle2 : t.tone === 'error' ? AlertCircle : Info
          const color = t.tone === 'success' ? 'text-success' : t.tone === 'error' ? 'text-danger' : 'text-accent-ink'
          return (
            <div
              key={t.id}
              role={t.tone === 'error' ? 'alert' : 'status'}
              className="pointer-events-auto flex items-start gap-3 rounded-2xl border border-line-strong bg-glass backdrop-blur-xl px-4 py-3 shadow-pop animate-rise"
            >
              <Icon className={`size-5 shrink-0 mt-0.5 ${color}`} />
              <p className="text-sm font-medium flex-1">{t.text}</p>
              <button onClick={() => setList((l) => l.filter((x) => x.id !== t.id))} aria-label="Fechar aviso" className="text-ink-faint hover:text-ink">
                <X className="size-4" />
              </button>
            </div>
          )
        })}
      </div>
    </Ctx.Provider>
  )
}
