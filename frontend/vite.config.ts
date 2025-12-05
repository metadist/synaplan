import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { resolve } from 'path'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig(({ mode }) => {
  // Library mode for widget bundle
  if (mode === 'widget') {
    return {
      plugins: [vue()],
      resolve: {
        alias: {
          '@': fileURLToPath(new URL('./src', import.meta.url))
        }
      },
      define: {
        'process.env.NODE_ENV': JSON.stringify('production')
      },
      build: {
        lib: {
          entry: resolve(projectRoot, 'src/widget.ts'),
          name: 'SynaplanWidget',
          fileName: 'widget',
          formats: ['iife']
        },
        rollupOptions: {
          output: {
            entryFileNames: 'widget.js',
            // Inline all CSS into JS for single-file widget
            inlineDynamicImports: true,
            assetFileNames: (assetInfo) => {
              if (assetInfo.name === 'style.css') return 'widget.css'
              return assetInfo.name || 'asset'
            }
          }
        },
        outDir: 'dist-widget',
        emptyOutDir: true,
        cssCodeSplit: false,
        minify: 'terser',
        terserOptions: {
          compress: {
            drop_console: true
          }
        }
      }
    }
  }

  // Default app mode
  const env = loadEnv(mode, process.cwd(), '')
  const basePath = env.VITE_BASE_PATH || '/'
  const backendUrl = env.BACKEND_URL || 'http://localhost:8000'

  return {
    base: basePath,
    plugins: [vue()],
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
