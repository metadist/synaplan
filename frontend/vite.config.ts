import { defineConfig, loadEnv, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { spawn } from 'node:child_process'
import { fileURLToPath, URL } from 'node:url'

/**
 * Plugin to create .gitkeep file in output directory after build
 * Only runs in development mode (when NODE_ENV !== 'production')
 */
export function gitkeepPlugin(): Plugin {
  const isDev = process.env.NODE_ENV !== 'production'

  return {
    name: 'gitkeep',
    async writeBundle(options) {
      if (!isDev) return

      const fs = await import('fs/promises')
      const path = await import('path')

      // Get outDir from writeBundle options
      const outDir = options.dir || 'dist'
      const gitkeepPath = path.join(outDir, '.gitkeep')

      try {
        await fs.writeFile(gitkeepPath, '', 'utf8')
        console.log(`✓ Created ${gitkeepPath}`)
      } catch (error) {
        console.warn('Failed to create .gitkeep:', error)
      }
    },
  }
}

/**
 * Dev server only: regenerate src/generated/api-schemas.ts when the backend
 * OpenAPI spec changed (pull, branch switch, edited annotations); Vite reloads
 * the page when the file changes. Runs on full page loads and at most once per
 * interval instead of polling: the dev backend rebuilds the spec on every
 * request (~1s of CPU).
 */
export function openapiSchemaSyncPlugin(): Plugin {
  const CHECK_INTERVAL_MS = 30_000
  let root = ''
  let lastCheck = 0
  let running = false

  return {
    name: 'openapi-schema-sync',
    apply: 'serve',
    configResolved(config) {
      root = config.root
    },
    transformIndexHtml() {
      const now = Date.now()
      if (running || now - lastCheck < CHECK_INTERVAL_MS) return
      lastCheck = now
      running = true
      const child = spawn(process.execPath, ['scripts/generate-schemas.js', '--if-changed'], {
        cwd: root,
        stdio: 'inherit',
      })
      child.once('exit', () => {
        running = false
      })
    },
  }
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const basePath = env.VITE_BASE_PATH || '/'
  const backendUrl = env.BACKEND_URL || 'http://localhost:8000'

  // Dev server allowed hosts (Vite's anti DNS-rebinding guard).
  // EMPTY (default) → the guard is ignored and EVERY host is accepted, so the
  // dev server answers to all requests out of the box (any domain / reverse
  // proxy). SET to a comma-separated list (e.g. "app.example.com,dev.example.com")
  // → ONLY those hosts may connect; everything else is rejected.
  // "true"/"all" are explicit aliases for "allow every host".
  const allowedHostsEnv = (env.ALLOWED_HOSTS ?? '').trim()
  const allowedHosts =
    allowedHostsEnv === '' || allowedHostsEnv === 'true' || allowedHostsEnv === 'all'
      ? true
      : allowedHostsEnv
          .split(',')
          .map((h) => h.trim())
          .filter(Boolean)

  return {
    base: basePath,
    plugins: [vue(), gitkeepPlugin(), openapiSchemaSyncPlugin()],
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      rollupOptions: {
        output: {
          manualChunks(id: string) {
            if (!id.includes('node_modules')) return undefined

            // Core framework — changes infrequently, loaded on every page
            if (
              /\/node_modules\/(vue|@vue|vue-router|pinia|vue-demi|vue-i18n|@intlify|zod)\//.test(
                id
              )
            ) {
              return 'vendor-core'
            }

            // Markdown processing — loaded with chat views
            if (/\/node_modules\/(marked|dompurify)\//.test(id)) {
              return 'vendor-markdown'
            }

            // Syntax highlighting — dynamically loaded when code blocks are rendered
            if (id.includes('/node_modules/highlight.js/')) {
              return 'vendor-highlight'
            }

            // Charts — only used in admin/statistics views
            if (/\/node_modules\/(chart\.js|vue-chartjs)\//.test(id)) {
              return 'vendor-charts'
            }

            // 3D graphics — only used in memory graph visualization
            if (id.includes('/node_modules/three/')) {
              return 'vendor-three'
            }

            return undefined
          },
        },
      },
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      allowedHosts,
      proxy: {
        '/api': {
          target: backendUrl,
          changeOrigin: true,
        },
        // Proxy shared chat pages to backend for OG meta tags
        // Social media crawlers (Facebook, LinkedIn, Twitter) don't execute JS
        // so the backend must serve pre-rendered HTML with meta tags
        '/shared': {
          target: backendUrl,
          changeOrigin: true,
        },
        // Centrifugo realtime WebSocket gateway.
        //
        // In production Caddy serves the dashboard AND reverse-proxies
        // `/connection/*` to Centrifugo on the same origin, so the operator
        // browser opens `wss://app.example.com/connection/websocket` without
        // any CORS or cross-origin gymnastics.
        //
        // In dev the dashboard is served by Vite on :5173 while Caddy +
        // Centrifugo live behind :8000. Without this proxy the operator
        // RealtimeClient resolves the WS URL from `window.location.host`,
        // tries `ws://localhost:5173/connection/websocket`, fails, and the
        // ConnectionStatusBadge flips to "Connection Error" — even though
        // visitor widgets (which derive the URL from apiBaseUrl) keep
        // working. `ws: true` is what unlocks the WS upgrade; without it
        // Vite hijacks the request as plain HTTP and the upgrade 404s.
        '/connection': {
          target: backendUrl,
          changeOrigin: true,
          ws: true,
        },
      },
    },
    test: {
      globals: true,
      environment: 'happy-dom',
      setupFiles: ['./tests/unit/setup-env.ts', './tests/unit/setup.ts'],
      include: ['tests/unit/**/*.{test,spec}.{js,ts}'],
      exclude: ['tests/e2e/**', 'node_modules/**'],
      coverage: {
        provider: 'v8',
        reporter: ['text', 'json', 'html'],
      },
    },
  }
})
