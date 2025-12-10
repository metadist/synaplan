import { defineConfig, loadEnv, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { resolve } from 'path'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

/**
 * Plugin to create .gitkeep file in output directory after build
 */
export function gitkeepPlugin(): Plugin {
  return {
    name: 'gitkeep',
    async writeBundle(options) {
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
    }
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
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    },
    server: {
      proxy: {
        '/api': {
          target: backendUrl,
          changeOrigin: true,
        }
      }
    },
    test: {
      globals: true,
      environment: 'happy-dom',
      setupFiles: ['./tests/setup.ts'],
      coverage: {
        provider: 'v8',
        reporter: ['text', 'json', 'html'],
      },
    },
  }
})
