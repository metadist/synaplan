import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  BUNDLE_PANEL_I18N_NAMESPACES,
  CHROME_I18N_NAMESPACES,
  I18N_NAMESPACES,
  isI18nNamespace,
  NAMESPACE_KEYS,
  type I18nNamespace,
} from '@/i18n/namespaces'

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const srcRoot = resolve(frontendRoot, 'src')

/**
 * de-only leftovers that predate the gated `auth.*` tree (same list as
 * namespaceMap.spec.ts). They load with the auth namespace.
 */
const EXTRA_AUTH_TOP_LEVELS = new Set([
  'login',
  'register',
  'forgotPassword',
  'verifyEmail',
  'emailVerified',
])

function topLevelToNamespace(top: string): I18nNamespace | null {
  for (const namespace of I18N_NAMESPACES) {
    if ((NAMESPACE_KEYS[namespace] as readonly string[]).includes(top)) {
      return namespace
    }
  }
  if (EXTRA_AUTH_TOP_LEVELS.has(top)) {
    return 'auth'
  }
  return null
}

// Static $t('top…') / t("top…") / t(`top…`) calls. The \b keeps DOM/storage
// lookalikes (import('vue'), createElement('div'), getItem('x')) out; the
// captured prefix also covers dynamic tails (t(`bundle.check.${c}`) → bundle).
const T_CALL = /\bt\(\s*['"`]([A-Za-z0-9_]+)/g

function isSkippedSpecifier(specifier: string): boolean {
  return (
    specifier === '@/router' ||
    specifier.startsWith('@/router/') ||
    specifier === '@/i18n' ||
    specifier.startsWith('@/i18n/') ||
    specifier.endsWith('.css')
  )
}

function resolveToFile(sourceFile: string, specifier: string): string | null {
  if (isSkippedSpecifier(specifier)) {
    return null
  }
  const base = specifier.startsWith('@/')
    ? resolve(srcRoot, specifier.slice('@/'.length))
    : resolve(dirname(sourceFile), specifier)
  const candidates = [
    base,
    `${base}.vue`,
    `${base}.ts`,
    resolve(base, 'index.ts'),
    resolve(base, 'index.vue'),
  ]
  for (const candidate of candidates) {
    if (!candidate.startsWith(srcRoot)) {
      continue
    }
    if (
      candidate.endsWith('.d.ts') ||
      candidate.endsWith('.spec.ts') ||
      candidate.includes('/tests/')
    ) {
      continue
    }
    if (existsSync(candidate) && statSync(candidate).isFile()) {
      return candidate
    }
  }
  return null
}

interface ParsedRoute {
  path: string
  componentFile: string
  declared: string[]
  isPublicNoAuth: boolean
}

function parseRoutes(): ParsedRoute[] {
  const routerFile = resolve(srcRoot, 'router/index.ts')
  const routerSrc = readFileSync(routerFile, 'utf8')
  const staticComponents = new Map<string, string>()
  for (const match of routerSrc.matchAll(/import\s+(\w+)\s+from\s*['"]([^'"]+)['"]/g)) {
    staticComponents.set(match[1], match[2])
  }
  const routes: ParsedRoute[] = []
  const chunks = routerSrc.split(/\n(?=\s*\{?\s*path:\s*')/)
  for (const chunk of chunks) {
    const pathMatch = chunk.match(/path:\s*'([^']+)'/)
    if (!pathMatch) {
      continue
    }
    const head = chunk.slice(0, 3000)
    if (/redirect\s*:/.test(head) && !/component\s*:/.test(head)) {
      continue
    }
    let componentFile: string | null = null
    const dynamicComponent = head.match(/component:\s*\(\)\s*=>\s*import\(['"]([^'"]+)['"]\)/)
    if (dynamicComponent) {
      componentFile = resolveToFile(routerFile, dynamicComponent[1])
    } else {
      const identifier = head.match(/component:\s*(\w+)/)
      const specifier = identifier ? staticComponents.get(identifier[1]) : undefined
      if (specifier) {
        componentFile = resolveToFile(routerFile, specifier)
      }
    }
    if (!componentFile) {
      continue
    }
    const declared: string[] = []
    const i18nMatch = head.match(/i18n:\s*\[([^\]]*)\]/)
    if (i18nMatch) {
      for (const match of i18nMatch[1].matchAll(/'([^']+)'/g)) {
        declared.push(match[1])
      }
      if (i18nMatch[1].includes('BUNDLE_PANEL_I18N_NAMESPACES')) {
        declared.push(...BUNDLE_PANEL_I18N_NAMESPACES)
      }
    }
    // Mirror of i18nNamespacesForRoute in router/index.ts.
    const isPublicNoAuth = /public:\s*true/.test(head) && /requiresAuth:\s*false/.test(head)
    routes.push({ path: pathMatch[1], componentFile, declared, isPublicNoAuth })
  }
  return routes
}

function loadedNamespacesFor(route: ParsedRoute): Set<I18nNamespace> {
  const loaded = new Set<I18nNamespace>(['core'])
  for (const entry of route.declared) {
    if (isI18nNamespace(entry)) {
      loaded.add(entry)
    }
  }
  if (!route.isPublicNoAuth) {
    for (const namespace of CHROME_I18N_NAMESPACES) {
      loaded.add(namespace)
    }
  }
  return loaded
}

/**
 * Minimum namespaces per route, triaged by hand (PR #2032 review). A fully
 * automatic "every statically reachable key must load" check is unsound here:
 * ConfigView statically imports 15 sections but renders one per route behind
 * v-if, ToolsView keeps a legacy dual-view branch that its route can never
 * reach, and shared components carry prop-gated branches (UnsavedChangesBar's
 * preview button has no passer). Each entry below names UI its route really
 * renders — shrinking any of them reintroduces raw keys on first paint.
 */
interface ExpectedMinimum {
  path: string
  namespaces: readonly I18nNamespace[]
  reason: string
}

const EXPECTED_MINIMUM: readonly ExpectedMinimum[] = [
  {
    path: '/',
    namespaces: ['chat', 'files', 'knowledge', 'tools', 'assistants', 'config'],
    reason:
      'message badges (knowledge), media-job/plugin states (tools), assistant banner (assistants), model sorting + desktop-run (config)',
  },
  {
    path: '/shared/:lang([a-z]{2})/:token',
    namespaces: ['chat', 'files', 'knowledge', 'auth'],
    reason: 'memory/feedback badges via MessageText (knowledge), demo setup CTA (auth)',
  },
  {
    path: '/shared/:token',
    namespaces: ['chat', 'files', 'knowledge', 'auth'],
    reason: 'memory/feedback badges via MessageText (knowledge), demo setup CTA (auth)',
  },
  {
    path: '/channels/widgets',
    namespaces: ['widgets', 'config'],
    reason: 'the widget editor reuses the model capability labels (config)',
  },
  {
    path: '/channels/widgets/:widgetId/chats',
    namespaces: ['widgets', 'knowledge', 'config'],
    reason: 'session transcripts (knowledge), model capability labels (config)',
  },
  {
    path: '/channels/widgets/:widgetId',
    namespaces: ['widgets', 'config'],
    reason: 'the widget editor reuses the model capability labels (config)',
  },
  {
    path: '/statistics',
    namespaces: ['admin', 'config'],
    reason: 'usage tables reuse the action labels (config)',
  },
  {
    path: '/ai/providers',
    namespaces: ['tools', 'config'],
    reason: 'provider cards reuse the provider labels (config)',
  },
]

function srcFiles(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith('.')) {
      continue
    }
    const full = resolve(dir, entry.name)
    if (entry.isDirectory()) {
      out.push(...srcFiles(full))
    } else if (
      (entry.name.endsWith('.vue') || entry.name.endsWith('.ts')) &&
      !entry.name.endsWith('.d.ts') &&
      !entry.name.endsWith('.spec.ts')
    ) {
      out.push(full)
    }
  }
  return out
}

function shortName(file: string): string {
  return file.startsWith(`${frontendRoot}/`) ? file.slice(frontendRoot.length + 1) : file
}

/**
 * Dead code, excluded from the dead-key scan: 1,434-line WIP view, unrouted
 * and unimported anywhere (verified 2026-09-20). Its `neuroExplorer.*` keys
 * were never added to the catalog. When this view gets a route, delete this
 * exclusion and add the keys to all five locales + NAMESPACE_KEYS instead.
 */
const EXCLUDED_FROM_DEAD_KEY_SCAN = new Set([resolve(srcRoot, 'views/NeuroExplorerView.vue')])

const routes = parseRoutes()

describe('i18n route coverage', () => {
  it('parses every routed view (guard against router-format drift)', () => {
    const paths = routes.map((route) => route.path)
    for (const expected of ['/', '/login', '/settings', '/shared/:token']) {
      expect(paths, 'route parser missed known routes').toContain(expected)
    }
    expect(paths.length, 'route parser returned suspiciously few routes').toBeGreaterThan(40)
  })

  it('declares only valid namespaces in every meta.i18n', () => {
    const invalid: string[] = []
    for (const route of routes) {
      for (const entry of route.declared) {
        if (!isI18nNamespace(entry)) {
          invalid.push(`${route.path}: ${entry}`)
        }
      }
    }
    expect(invalid, 'typo in meta.i18n — the loader silently drops unknown entries').toEqual([])
  })

  it('uses only top-level keys that exist in the EN catalog', () => {
    const unknown = new Map<string, Set<string>>()
    for (const file of srcFiles(srcRoot)) {
      if (EXCLUDED_FROM_DEAD_KEY_SCAN.has(file)) {
        continue
      }
      const src = readFileSync(file, 'utf8')
      for (const match of src.matchAll(T_CALL)) {
        const top = match[1]
        if (!topLevelToNamespace(top)) {
          if (!unknown.has(top)) {
            unknown.set(top, new Set())
          }
          unknown.get(top)?.add(shortName(file))
        }
      }
    }
    const report = [...unknown.entries()].map(
      ([top, files]) => `${top} (used in ${[...files].sort().join(', ')})`
    )
    expect(report, 'code references top-level keys absent from en — always raw keys').toEqual([])
  })

  it.each([...EXPECTED_MINIMUM])('route $path loads at least $namespaces', (expected) => {
    const route = routes.find((candidate) => candidate.path === expected.path)
    expect(route, `curated contract references unknown route ${expected.path}`).toBeDefined()
    if (!route) {
      return
    }
    const loaded = loadedNamespacesFor(route)
    const missing = expected.namespaces.filter((namespace) => !loaded.has(namespace))
    expect(missing, `route ${route.path} regressed (${expected.reason})`).toEqual([])
  })
})
