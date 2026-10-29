import { useState, type ReactNode } from 'react'
import { Modal } from './Modal'
import { Field, Spinner } from './ui'

/** Botão que abre uma confirmação. Com `requireText`, só confirma digitando o texto exato. */
export function ConfirmButton({
  label, title, children, confirmLabel, onConfirm, requireText, danger = true, className,
}: {
  label: ReactNode
  title: string
  children?: ReactNode
  confirmLabel: string
  onConfirm: () => Promise<unknown>
  requireText?: string
  danger?: boolean
  className?: string
}) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [typed, setTyped] = useState('')
  const [error, setError] = useState<string | null>(null)
  const ok = !requireText || typed.trim() === requireText
  return (
    <>
      <button type="button" className={className ?? (danger ? 'btn-ghost text-danger' : 'btn-ghost')} onClick={() => setOpen(true)}>{label}</button>
      <Modal open={open} onClose={() => !busy && setOpen(false)} title={title}>
        <div className="space-y-4">
          {children && <div className="text-sm text-ink-soft">{children}</div>}
          {requireText && (
            <Field label="Digite o nome do bolão para confirmar" name="confirm" autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} help={`Exatamente: ${requireText}`} />
          )}
          {error && <p className="text-sm font-medium text-danger">{error}</p>}
          <div className="flex gap-2 justify-end">
            <button className="btn-ghost" onClick={() => setOpen(false)} disabled={busy}>Cancelar</button>
            <button
              className={danger ? 'btn-danger' : 'btn-primary'}
              disabled={!ok || busy}
              onClick={async () => {
                setBusy(true)
                setError(null)
                try {
                  await onConfirm()
                  setOpen(false)
                  setTyped('')
                } catch (e) {
                  setError((e as Error).message)
                } finally {
                  setBusy(false)
                }
              }}
            >
              {busy ? <Spinner /> : confirmLabel}
            </button>
          </div>
        </div>
      </Modal>
    </>
  )
}
