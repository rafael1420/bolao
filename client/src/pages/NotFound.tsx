import { Link } from 'react-router-dom'
import { Compass } from 'lucide-react'
import { EmptyState } from '../components/ui'

export default function NotFound() {
  return (
    <EmptyState icon={<Compass className="size-6" />} title="Página não encontrada">
      <p>O endereço pode ter mudado.</p>
      <Link to="/" className="btn-primary mt-4">
        Voltar aos meus bolões
      </Link>
    </EmptyState>
  )
}
