import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// O app React fica em client/ e vai compilado para dist/client, que o servidor Node entrega.
export default defineConfig({
  root: 'client',
  plugins: [react(), tailwindcss()],
  build: {
    outDir: '../dist/client',
    emptyOutDir: true,
    assetsDir: 'app',
  },
  server: {
    proxy: { '/api': 'http://127.0.0.1:3000' },
  },
})
