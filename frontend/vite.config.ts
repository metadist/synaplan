import { defineConfig, loadEnv, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { resolve } from 'path'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

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

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const basePath = env.VITE_BASE_PATH || '/'
  const backendUrl = env.BACKEND_URL || 'http://localhost:8000'

  return {
    base: basePath,
    plugins: [vue(), gitkeepPlugin()],
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
