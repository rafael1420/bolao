import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

/** Janela (bottom sheet no celular) com <dialog> nativo: foco preso e Esc fecham sozinhos. */
export function Modal({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const d = ref.current
    if (!d) return
    if (open && !d.open) d.showModal()
    if (!open && d.open) d.close()
  }, [open])
  return (
    <dialog
      ref={ref}
      onClose={onClose}
      onClick={(e) => e.target === ref.current && onClose()}
      className="m-0 mt-auto sm:m-auto w-full max-w-none sm:max-w-lg bg-transparent p-0 backdrop:bg-black/50 backdrop:backdrop-blur-sm open:animate-rise"
    >
      <div className="bg-surface text-ink border border-line rounded-t-3xl sm:rounded-3xl p-5 pb-[calc(20px+env(safe-area-inset-bottom))] sm:pb-5 max-h-[85dvh] overflow-y-auto shadow-pop">
        <div className="flex items-center justify-between gap-4 mb-4">
          <h2 className="text-lg font-bold">{title}</h2>
          <button onClick={onClose} className="size-9 grid place-items-center rounded-xl hover:bg-surface-2 text-ink-soft" aria-label="Fechar">
            <X className="size-5" />
          </button>
        </div>
        {open && children}
      </div>
    </dialog>
  )
}
