import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// No loadEnv: local credentials and root .env never become VITE_* client data.
const target = process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8000'
if (! ['http://127.0.0.1:8000', 'http://http:8000', 'http://e2e-api:8000'].includes(target)) {
  throw new Error('Unapproved local API proxy target')
}
export default defineConfig({
  plugins: [react()],
  server: {
    host: process.env.VITE_CONTAINER === '1' ? '0.0.0.0' : '127.0.0.1',
    port: 5173, strictPort: true, cors: false,
    allowedHosts: ['127.0.0.1'],
    fs: { strict: true, allow: [process.cwd()] },
    proxy: {
      '^/api(?:/|$)': { target },
      '^/sanctum(?:/|$)': { target },
      '^/logout(?:\\?|$)': { target },
      '^/login(?:\\?|$)': {
        target,
        bypass(req) {
          // Only GET/HEAD is the SPA. POST remains Laravel's CSRF-protected API.
          if (req.method === 'GET' || req.method === 'HEAD') return '/index.html'
        },
      },
    },
  },
  test: { environment: 'jsdom', setupFiles: ['./src/test/setup.ts'], include: ['src/**/*.test.{ts,tsx}'] },
})
