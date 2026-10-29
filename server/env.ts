// Configuração pelo painel da Hostinger (Variáveis de ambiente do Web App) ou por um arquivo .env local.
import { existsSync, readFileSync } from 'node:fs'

// .env local (desenvolvimento). No servidor, as variáveis vêm do hPanel.
if (existsSync('.env')) {
  for (const line of readFileSync('.env', 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '')
  }
}

const get = (...keys: string[]) => {
  for (const k of keys) {
    const v = process.env[k]
    if (v !== undefined && v !== '') return v
  }
  return ''
}

function fromUrl() {
  const raw = get('DATABASE_URL')
  if (!raw) return null
  const u = new URL(raw)
  return {
    host: u.hostname,
    port: Number(u.port || 3306),
    user: decodeURIComponent(u.username),
    password: decodeURIComponent(u.password),
    database: u.pathname.replace(/^\//, ''),
  }
}

export const env = {
  port: Number(get('PORT') || 3000),
  db: fromUrl() ?? {
    host: get('DB_HOST') || '127.0.0.1',
    port: Number(get('DB_PORT') || 3306),
    user: get('DB_USER', 'DB_USERNAME'),
    password: get('DB_PASSWORD', 'DB_PASS'),
    database: get('DB_NAME', 'DB_DATABASE'),
  },
  adminEmail: get('ADMIN_EMAIL').toLowerCase(),
  siteName: get('SITE_NAME') || 'Conferência Bolão',
  /** Endereço público (links de convite e de nova senha). Sem ele, usa o endereço da requisição. */
  siteUrl: get('SITE_URL').replace(/\/+$/, ''),
  smtp: {
    host: get('SMTP_HOST'),
    port: Number(get('SMTP_PORT') || 465),
    user: get('SMTP_USER'),
    pass: get('SMTP_PASSWORD', 'SMTP_PASS'),
    from: get('MAIL_FROM', 'SMTP_USER'),
  },
  /** "off" desliga a sincronização automática com a ESPN (útil em testes). */
  sync: get('SYNC') !== 'off',
  production: get('NODE_ENV') === 'production',
}
