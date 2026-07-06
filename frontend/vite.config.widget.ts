import { defineConfig, Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { resolve } from 'path'
import { gitkeepPlugin } from './vite.config'

/**
 * Plugin to generate buildInfo.json for build verification (dev only)
 * - Generates fresh timestamp and hash at the start of each build
 * - Writes buildInfo.json to dist-widget for verification
 * - Only enabled in development mode
 */
function buildTimestampPlugin(mode: string): Plugin {
  let buildTimestamp: string
  let buildHash: string
  const isDev = mode !== 'production'

  return {
    name: 'build-timestamp',
    buildStart() {
      if (!isDev) return

      // Generate fresh timestamp and random hash for each build
      buildTimestamp = new Date().toISOString()
      buildHash = Math.random().toString(36).substring(2, 8)
      console.log(`🔨 Widget build started at ${buildTimestamp} {hash: ${buildHash}}`)
    },
    async writeBundle(options, bundle) {
      if (!isDev) return

      // Write buildInfo.json directly to the output directory
      const buildInfo = {
        timestamp: buildTimestamp,
        hash: buildHash,
        date: new Date(buildTimestamp).toLocaleString(),
        env: mode,
      }

      const fs = await import('fs/promises')
      const path = await import('path')
      const outDir = options.dir || 'dist-widget'
      const buildInfoPath = path.join(outDir, 'buildInfo.json')

      try {
        await fs.writeFile(buildInfoPath, JSON.stringify(buildInfo, null, 2))
        console.log(`✅ Widget build completed - buildInfo.json generated with hash: ${buildHash}`)
      } catch (error) {
        console.error(`Failed to write buildInfo.json:`, error)
      }
    },
  }
}

/**
 * Vite configuration for building widget as ES module
 *
 * Builds ONE fully self-contained widget.js ES module (no code-splitting):
 * - All dynamic imports (Vue, ChatWidget, i18n, styles) are inlined into
 *   widget.js via `inlineDynamicImports`.
 *
 * Why no chunks: the widget is embedded on third-party sites and we redeploy
 * daily. Hash-named chunks (`chunks/ChatWidget-<hash>.js`) are deleted on every
 * build (`emptyOutDir`), so any cached/edge-cached widget.js that still points
 * at an old hash would 404 at runtime ("Failed to fetch dynamically imported
 * module"). A single self-contained file has zero runtime dependencies, so a
 * stale cached widget.js keeps working across redeploys.
 *
 * Watch Mode Configuration:
 * - Use --watch CLI flag for development (configured in docker-compose.yml)
 * - Polling configuration enabled when --watch flag detected
 * - See: https://vite.dev/config/build-options#build-watch
 */
export default defineConfig(({ mode }) => ({
  plugins: [vue(), gitkeepPlugin(), buildTimestampPlugin(mode)],

  // Use relative base for chunks to be resolved relative to widget.js location
  base: './',

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  build: {
    // Watch configuration for Docker bind mounts (activated via --watch CLI flag)
    // https://vite.dev/config/build-options#build-watch
    // https://rollupjs.org/configuration-options/#watch
    // Note: Using process.argv check because Vite doesn't expose watch mode in defineConfig callback
    // See: https://github.com/vitejs/vite/discussions/7565
    watch:
      process.argv.includes('--watch') || process.argv.includes('-w')
        ? {
            include: 'src/**',
            exclude: ['node_modules/**', 'dist-widget/**'],
            // Debounce rebuilds when multiple files change at once (ms)
            buildDelay: 500,
            // Chokidar options for file system polling in Docker bind mounts.
            // Keep config minimal to limit memory growth (see #515).
            chokidar: {
              usePolling: true,
              interval: 2000,
            },
          }
        : null,

    rollupOptions: {
      preserveEntrySignatures: 'strict',
      input: {
        widget: resolve(__dirname, 'src/widget.ts'),
      },
      output: {
        format: 'es',
        entryFileNames: '[name].js',
        // Inline every dynamic import into widget.js so the embed has NO
        // external chunk dependencies. This is the fix for the post-deploy
        // "chunks/*.js 404" failures on customer sites (e.g. roatel.com).
        inlineDynamicImports: true,
        assetFileNames: 'assets/[name]-[hash][extname]',
        strictExecutionOrder: true,
      },
    },

    outDir: 'dist-widget',
    emptyOutDir: true,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: false, // Keep console for debugging widget issues
        passes: 2,
      },
    },
    sourcemap: false,
  },
}))
