import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { WIDGET_I18N_NAMESPACES } from '@/i18n/namespaces'

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

function readSrc(relative: string): string {
  return readFileSync(resolve(frontendRoot, relative), 'utf8')
}

describe('i18n bundle split', () => {
  it('keeps the app loader on a lazy glob of namespace files', () => {
    const src = readSrc('src/i18n/loader.ts')
    expect(src).toContain("import.meta.glob<LocaleModule>('./locales/*/*.json')")
    expect(src).not.toContain('eager: true')
  })

  it('keeps the widget loader on core/chat/widgets only', () => {
    const src = readSrc('src/i18n/widget.ts')
    expect(src).toContain('./locales/*/core.json')
    expect(src).toContain('./locales/*/chat.json')
    expect(src).toContain('./locales/*/widgets.json')
    expect(src).not.toMatch(/admin\.json|config\.json|tools\.json|settings\.json/)
    expect([...WIDGET_I18N_NAMESPACES]).toEqual(['core', 'chat', 'widgets'])
  })

  it('does not let the embed import the full app i18n loader', () => {
    const src = readSrc('src/widget.ts')
    expect(src).toContain("import('./i18n/widget')")
    expect(src).not.toMatch(/import\('\.\/i18n'\)/)
  })

  it('stubs the SPA router in the widget Vite config so httpClient cannot inline it', () => {
    const src = readSrc('vite.config.widget.ts')
    expect(src).toContain("find: '@/router/setupGate'")
    expect(src).toContain('find: /^@\\/router$/')
    expect(src).toContain('find: /^@\\/i18n$/')
    expect(src).toContain('widget-embed-stubs/router.ts')
    expect(src).toContain('widget-embed-stubs/i18n.ts')
  })

  it('keeps config.taskPrompts out of the built app entry chunk when dist exists', () => {
    const assetsDir = resolve(frontendRoot, 'dist/assets')
    if (!existsSync(assetsDir)) {
      return
    }
    const files = readdirSync(assetsDir).filter((name) => name.endsWith('.js'))
    const entry = files.find((name) => name.startsWith('index-') || name.startsWith('main-'))
    const candidates = entry ? [entry] : files.filter((name) => name.includes('index'))
    expect(candidates.length, 'expected a built JS chunk in dist/assets').toBeGreaterThan(0)
    for (const name of candidates.slice(0, 3)) {
      const code = readFileSync(resolve(assetsDir, name), 'utf8')
      if (name.startsWith('index-') || name.startsWith('main-')) {
        expect(code, name).not.toContain('config.taskPrompts')
      }
    }
  })

  it('keeps admin namespace strings out of the widget bundle when dist-widget exists', () => {
    const widgetJs = resolve(frontendRoot, 'dist-widget/widget.js')
    if (!existsSync(widgetJs)) {
      return
    }
    const code = readFileSync(widgetJs, 'utf8')
    expect(code).not.toContain('Missing i18n chunk')
    expect(code).not.toContain('config.taskPrompts')
    expect(code).not.toContain('providerHelp')
    expect(code).not.toContain('locales/en/admin.json')
    expect(code).not.toContain('locales/en/config.json')
    expect(code).toContain('Missing widget i18n chunk')
  })
})
