// Servidor do Conferência Bolão: API em /api e o app React (dist/client) para todo o resto.
// Na Hostinger: Web App Node.js, build "npm run build", arquivo de entrada dist/server.js.
import { serve } from '@hono/node-server'
import { serveStatic } from '@hono/node-server/serve-static'
import { Hono } from 'hono'
import { compress } from 'hono/compress'
import { secureHeaders } from 'hono/secure-headers'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { api } from './api.ts'
import { env } from './env.ts'
import { migrate } from './schema.ts'
import { startScheduler } from './sync/espn.ts'

// dist/server.js → dist/client; em desenvolvimento (tsx server/index.ts) → dist/client também.
const here = dirname(fileURLToPath(import.meta.url))
const clientDir = [join(here, 'client'), join(here, '..', 'dist', 'client')].find((d) => existsSync(join(d, 'index.html')))
const root = clientDir ? relative(process.cwd(), clientDir) || '.' : null

const app = new Hono()
app.use('*', secureHeaders({ crossOriginResourcePolicy: false, crossOriginEmbedderPolicy: false, contentSecurityPolicy: undefined, referrerPolicy: 'same-origin' }))
app.use('*', compress())
app.get('/healthz', (c) => c.text('ok'))
app.route('/api', api)

// Links da versão anterior (PHP) continuam funcionando.
app.get('/join_group.php', (c) => c.redirect(`/convite/${encodeURIComponent(c.req.query('code') ?? '')}`, 301))
app.get('/group.php', (c) => c.redirect(`/bolao/${Number(c.req.query('id')) || ''}`, 301))
app.get('/reset_password.php', (c) => c.redirect(`/nova-senha?token=${encodeURIComponent(c.req.query('token') ?? '')}`, 302))
app.get('/:page{[a-z_]+\\.php}', (c) => c.redirect('/', 301))

if (root) {
  // Arquivos do app têm hash no nome: cache de 1 ano.
  app.use('/app/*', async (c, next) => {
    await next()
    if (c.res.status === 200) c.header('Cache-Control', 'public, max-age=31536000, immutable')
  })
  app.use('*', serveStatic({ root }))
  const indexHtml = readFileSync(join(clientDir!, 'index.html'), 'utf8')
  app.get('*', (c) => {
    c.header('Cache-Control', 'no-cache')
    return c.html(indexHtml)
  })
} else {
  app.get('*', (c) => c.text('O app ainda não foi compilado. Rode "npm run build".', 503))
}

async function main() {
  try {
    await migrate()
  } catch (e) {
    console.error('[bolao] Não consegui preparar o banco. Confira DB_HOST, DB_NAME, DB_USER e DB_PASSWORD.', (e as Error).message)
  }
  if (env.sync) startScheduler()
  serve({ fetch: app.fetch, port: env.port }, (info) => console.log(`[bolao] no ar na porta ${info.port}`))
}

main()
