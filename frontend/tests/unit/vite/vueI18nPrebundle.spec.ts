import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createI18n } from 'vue-i18n'
import { optimizeDeps, resolveConfig } from 'vite'
import { describe, expect, it } from 'vitest'

/**
 * Regression for #1065: Vite 8.0/8.1 Rolldown dropped `init_*` helpers when
 * vue-i18n was pre-bundled, so the app died at startup with
 * `init_runtime_dom_esm_bundler is not defined`. Vite 8.3.0 ships the fix;
 * this test re-runs that prebundle so the exclude workaround cannot come back
 * silently and the same helper-drop cannot land unnoticed.
 */
const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

describe('vite vue-i18n prebundle (#1065)', () => {
  it('translates through the real vue-i18n runtime', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { hello: 'Hello from i18n' } },
    })
    expect(i18n.global.t('hello')).toBe('Hello from i18n')
  })

  it('does not exclude vue-i18n from dependency pre-bundling', async () => {
    const config = await resolveConfig(
      {
        configFile: resolve(frontendRoot, 'vite.config.ts'),
        root: frontendRoot,
        logLevel: 'error',
      },
      'serve'
    )
    const excluded = config.optimizeDeps.exclude ?? []
    expect(excluded).not.toContain('vue-i18n')
  })

  it('pre-bundles vue-i18n with every init_* helper defined', async () => {
    const config = await resolveConfig(
      {
        configFile: resolve(frontendRoot, 'vite.config.ts'),
        root: frontendRoot,
        logLevel: 'error',
        optimizeDeps: { include: ['vue-i18n'] },
      },
      'serve'
    )
    const meta = await optimizeDeps(config, true)
    const info = meta.optimized['vue-i18n']
    if (!info?.file) {
      throw new Error('vue-i18n must be in the optimizeDeps graph')
    }

    const code = readFileSync(info.file, 'utf8')
    expect(code.length).toBeGreaterThan(1000)

    const imported = new Set<string>()
    for (const block of code.matchAll(/import\s*\{([^}]+)\}\s*from\s*["'][^"']+["']/g)) {
      for (const spec of block[1].split(',')) {
        const parts = spec.trim().split(/\s+as\s+/)
        const local = (parts[1] ?? parts[0]).trim()
        if (local) {
          imported.add(local)
        }
      }
    }

    const called = [...code.matchAll(/\b(init_[A-Za-z0-9_]+)\s*\(/g)].map((m) => m[1])
    expect(called.length, 'expected the prebundle to call Rolldown init helpers').toBeGreaterThan(0)

    for (const name of new Set(called)) {
      const defined =
        imported.has(name) ||
        code.includes(`function ${name}`) ||
        new RegExp(`(?:var|const|let)\\s+${name}\\b`).test(code)
      expect(
        defined,
        `${name} is called in the vue-i18n prebundle but never defined or imported`
      ).toBe(true)
    }
  }, 60_000)
})
