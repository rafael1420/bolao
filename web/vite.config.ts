import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// O build vai para ../dist (index.html + app/), que o script de pacote junta com a API em PHP.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: '/',
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    assetsDir: 'app',
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8080',
      '/assets': 'http://127.0.0.1:8080',
    },
  },
})
