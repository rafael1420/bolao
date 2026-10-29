// Login: sessões guardadas no banco (o cookie leva só um token aleatório), limite de tentativas e
// links de nova senha. As senhas são bcrypt, as mesmas da versão anterior (quem já tinha conta entra igual).
import { createHash, randomBytes } from 'node:crypto'
import bcrypt from 'bcryptjs'
import type { Context } from 'hono'
import { getCookie, setCookie, deleteCookie } from 'hono/cookie'
import nodemailer from 'nodemailer'
import { exec, one, q } from './db.ts'
import { env } from './env.ts'
import { fromSql, nowTs, sqlTime } from './time.ts'

const COOKIE = 'bolao_sid'
export const SESSION_IDLE = 7 * 86400 // sem uso por 7 dias, o login acaba
export const SESSION_MAX = 30 * 86400 // e dura no máximo 30 dias

export type User = { id: number; name: string; email: string }

const sha256 = (s: string) => createHash('sha256').update(s).digest('hex')
export const randomToken = (bytes = 32) => randomBytes(bytes).toString('hex')

export const hashPassword = (pw: string) => bcrypt.hash(pw, 10)
export const checkPassword = (pw: string, hash: string) => bcrypt.compare(pw, hash).catch(() => false)

export const isAdmin = (u: User | null) => !!u && !!env.adminEmail && u.email.toLowerCase() === env.adminEmail

/** O site está em HTTPS? (a Hostinger fica na frente e avisa por X-Forwarded-Proto) */
const isHttps = (c: Context) => (c.req.header('x-forwarded-proto') ?? new URL(c.req.url).protocol.replace(':', '')) === 'https'

/** IP de quem fez a requisição (o proxy da Hostinger põe o IP real no X-Forwarded-For). */
export function clientIp(c: Context) {
  const fwd = c.req.header('x-forwarded-for')?.split(',')[0]?.trim()
  return (fwd || c.req.header('x-real-ip') || '0.0.0.0').slice(0, 45)
}

/** Endereço público do site, terminado sem "/". */
export function siteUrl(c: Context) {
  if (env.siteUrl) return env.siteUrl
  const host = c.req.header('x-forwarded-host') ?? c.req.header('host') ?? 'localhost'
  return `${isHttps(c) ? 'https' : 'http'}://${host}`
}

export async function startSession(c: Context, userId: number) {
  const token = randomToken()
  const now = nowTs()
  await exec('INSERT INTO sessions (id, user_id, created_at, last_seen) VALUES (?, ?, ?, ?)', [sha256(token), userId, now, now])
  setCookie(c, COOKIE, token, { httpOnly: true, secure: isHttps(c), sameSite: 'Lax', path: '/', maxAge: SESSION_MAX })
  // Limpeza: sessões vencidas.
  await exec('DELETE FROM sessions WHERE last_seen < ? OR created_at < ?', [now - SESSION_IDLE, now - SESSION_MAX])
}

export async function endSession(c: Context) {
  const token = getCookie(c, COOKIE)
  if (token) await exec('DELETE FROM sessions WHERE id = ?', [sha256(token)])
  deleteCookie(c, COOKIE, { path: '/' })
}

/** Quem está logado (ou null). Atualiza o "visto por último" no máximo a cada 5 minutos. */
export async function currentUser(c: Context): Promise<User | null> {
  const cached = c.get('user' as never) as User | null | undefined
  if (cached !== undefined) return cached
  const token = getCookie(c, COOKIE)
  let user: User | null = null
  if (token && /^[a-f0-9]{64}$/.test(token)) {
    const now = nowTs()
    const s = await one('SELECT s.id, s.created_at, s.last_seen, u.id AS uid, u.name, u.email FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [sha256(token)])
    if (s && now - s.last_seen <= SESSION_IDLE && now - s.created_at <= SESSION_MAX) {
      user = { id: s.uid, name: s.name, email: s.email }
      if (now - s.last_seen > 300) await exec('UPDATE sessions SET last_seen = ? WHERE id = ?', [now, s.id])
    } else if (s) {
      await exec('DELETE FROM sessions WHERE id = ?', [s.id])
    }
  }
  c.set('user' as never, user as never)
  return user
}

/** Outros aparelhos saem da conta (depois de trocar a senha); o atual continua. */
export async function endOtherSessions(c: Context, userId: number) {
  const token = getCookie(c, COOKIE)
  await exec('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [userId, token ? sha256(token) : ''])
}

/* ---------- Limite de tentativas: 5 erros em 15 min (e-mail + IP) bloqueiam por 15 min ---------- */

const WINDOW = 15 * 60
const MAX_FAILS = 5
const MAX_FAILS_IP = 30

/** Segundos até poder tentar de novo (0 = liberado). */
export async function lockSeconds(key: string, ip: string) {
  const since = sqlTime(nowTs() - WINDOW)
  let wait = 0
  const byKey = await q<{ attempted_at: string }>('SELECT attempted_at FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > ? ORDER BY attempted_at DESC LIMIT ?', [key, ip, since, MAX_FAILS])
  if (byKey.length >= MAX_FAILS) wait = fromSql(byKey[0].attempted_at) + WINDOW - nowTs()
  const byIp = await q<{ attempted_at: string }>('SELECT attempted_at FROM login_attempts WHERE ip = ? AND attempted_at > ? ORDER BY attempted_at DESC LIMIT ?', [ip, since, MAX_FAILS_IP])
  if (byIp.length >= MAX_FAILS_IP) wait = Math.max(wait, fromSql(byIp[0].attempted_at) + WINDOW - nowTs())
  return Math.max(0, wait)
}

export async function recordFailure(key: string, ip: string) {
  await exec('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)', [key.slice(0, 150), ip, sqlTime(nowTs())])
  await exec('DELETE FROM login_attempts WHERE attempted_at < ?', [sqlTime(nowTs() - 86400)])
}

export const clearFailures = (key: string, ip: string) => exec('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [key, ip])

export const waitText = (s: number) => {
  const m = Math.max(1, Math.ceil(s / 60))
  return `${m} ${m === 1 ? 'minuto' : 'minutos'}`
}

/* ---------- Nova senha por link (o banco guarda só o hash do token) ---------- */

const RESET_TTL = 3600 // link pedido pela pessoa: 1 hora
const RESET_ADMIN_TTL = 86400 // link gerado pela administração: 24 horas

export async function createReset(userId: number, by: 'user' | 'admin', ip: string) {
  const token = randomToken()
  const now = nowTs()
  await exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [sqlTime(now), userId])
  await exec('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
    userId, sha256(token), sqlTime(now + (by === 'admin' ? RESET_ADMIN_TTL : RESET_TTL)), ip, by, sqlTime(now),
  ])
  await exec('DELETE FROM password_resets WHERE expires_at < ?', [sqlTime(now - 30 * 86400)])
  return token
}

export const resetUrl = (c: Context, token: string) => `${siteUrl(c)}/nova-senha?token=${token}`

export async function findReset(token: string) {
  if (!/^[a-f0-9]{64}$/.test(token)) return null
  return one<{ id: number; user_id: number; email: string; name: string }>(
    'SELECT r.id, r.user_id, u.email, u.name FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?',
    [sha256(token), sqlTime(nowTs())],
  )
}

/** Troca a senha pelo link (uso único). Todas as sessões da conta caem. */
export async function consumeReset(token: string, password: string) {
  const r = await findReset(token)
  if (!r) return null
  const res = await exec('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [sqlTime(nowTs()), r.id])
  if (!res.affectedRows) return null
  await exec('UPDATE users SET password_hash = ? WHERE id = ?', [await hashPassword(password), r.user_id])
  await exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [sqlTime(nowTs()), r.user_id])
  await exec('DELETE FROM sessions WHERE user_id = ?', [r.user_id])
  await exec('DELETE FROM login_attempts WHERE email = ?', [r.email])
  return r
}

/** Pedidos de link por hora: 10 por IP e 3 por conta. */
export async function resetAllowed(ip: string, userId?: number) {
  const since = sqlTime(nowTs() - 3600)
  const byIp = await one("SELECT COUNT(*) AS n FROM password_resets WHERE requested_ip = ? AND created_by = 'user' AND created_at > ?", [ip, since])
  if (Number(byIp?.n) >= 10) return false
  if (userId === undefined) return true
  const byUser = await one("SELECT COUNT(*) AS n FROM password_resets WHERE user_id = ? AND created_by = 'user' AND created_at > ?", [userId, since])
  return Number(byUser?.n) < 3
}

/* ---------- E-mail (SMTP da Hostinger: smtp.hostinger.com, porta 465) ---------- */

const transport = env.smtp.host
  ? nodemailer.createTransport({ host: env.smtp.host, port: env.smtp.port, secure: env.smtp.port === 465, auth: { user: env.smtp.user, pass: env.smtp.pass } })
  : null

export const mailEnabled = () => transport !== null

export async function sendResetMail(to: string, name: string, url: string) {
  if (!transport) {
    console.warn('[bolao] SMTP não configurado: o e-mail de nova senha não foi enviado. Use o link da administração.')
    return false
  }
  try {
    await transport.sendMail({
      from: `"${env.siteName}" <${env.smtp.from}>`,
      to,
      subject: `Redefinir sua senha | ${env.siteName}`,
      text:
        `Olá, ${name}.\n\nRecebemos um pedido para redefinir a senha da sua conta no ${env.siteName}.\n` +
        `Para criar uma nova senha, abra o link abaixo (vale por 1 hora e funciona uma vez só):\n\n${url}\n\n` +
        'Se não foi você, ignore este e-mail. Sua senha continua a mesma.\n',
    })
    return true
  } catch (e) {
    console.error('[bolao] Falha ao enviar e-mail:', (e as Error).message)
    return false
  }
}
