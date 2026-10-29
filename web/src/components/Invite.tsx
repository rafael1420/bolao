import { useState } from 'react'
import { Check, Copy, Lock, MessageCircle, Share2 } from 'lucide-react'
import type { GroupInfo } from '../lib/types'
import { useToast } from './Toast'

export function InvitePanel({ group }: { group: GroupInfo }) {
  const toast = useToast()
  const [copied, setCopied] = useState(false)
  const text = `Entra no meu bolão "${group.name}" no Conferência Bolão: ${group.inviteUrl}`
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(group.inviteUrl)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast('Não deu para copiar. Selecione o link e copie.', 'error')
    }
  }
  const canShare = typeof navigator !== 'undefined' && 'share' in navigator
  return (
    <div className="space-y-4">
      <p className="text-sm text-ink-soft">Mande o link ou o código para quem você quer no bolão.</p>
      <div className="flex items-center gap-2 rounded-2xl bg-surface-2 border border-line p-2 pl-4">
        <input readOnly value={group.inviteUrl} onFocus={(e) => e.target.select()} className="flex-1 min-w-0 bg-transparent outline-none text-sm font-medium" aria-label="Link do convite" />
        <button onClick={copy} className={copied ? 'btn-sm btn bg-success text-white' : 'btn-primary btn-sm'}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          {copied ? 'Copiado' : 'Copiar'}
        </button>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <a className="btn-ghost" href={`https://wa.me/?text=${encodeURIComponent(text)}`} target="_blank" rel="noopener noreferrer">
          <MessageCircle className="size-4 text-[#25d366]" />WhatsApp
        </a>
        {canShare ? (
          <button className="btn-ghost" onClick={() => navigator.share({ title: group.name, text, url: group.inviteUrl }).catch(() => {})}>
            <Share2 className="size-4" />Compartilhar
          </button>
        ) : (
          <div className="rounded-2xl bg-surface-2 border border-line grid place-items-center text-sm">
            Código <strong className="display text-lg tracking-widest">{group.inviteCode}</strong>
          </div>
        )}
      </div>
      <p className="text-sm text-ink-faint flex items-center gap-2">
        {canShare && <>Código: <strong className="display text-base tracking-widest text-ink">{group.inviteCode}</strong> · </>}
        {group.hasPassword ? <><Lock className="size-3.5" />Pede senha para entrar.</> : 'Não pede senha.'}
      </p>
    </div>
  )
}
