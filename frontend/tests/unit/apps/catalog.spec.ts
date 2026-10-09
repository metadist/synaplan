import { beforeEach, describe, expect, it, vi } from 'vitest'

const runtime = vi.hoisted(() => ({
  modules: new Set<string>(),
  desktop: false,
  platformLinks: false,
  higgsfield: false,
  gateway: false,
  isAdmin: false,
}))

vi.mock('@/composables/useModuleFeature', () => ({
  isModuleConfigured: (id: string) => runtime.modules.has(id),
}))
vi.mock('@/composables/useDesktopAgentFeature', () => ({
  isDesktopAgentEnabled: () => runtime.desktop,
}))
vi.mock('@/composables/usePlatformLinksFeature', () => ({
  isPlatformLinksEnabled: () => runtime.platformLinks,
}))
vi.mock('@/composables/useAiAccounts', () => ({
  isHiggsfieldAccountsEnabled: () => runtime.higgsfield,
  loadGatewayEnabled: async () => runtime.gateway,
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: runtime.isAdmin }),
}))
vi.mock('@/services/api/m365Api', () => ({
  m365Api: { status: async () => ({ available: false }) },
}))
vi.mock('@/services/api/dropboxApi', () => ({
  dropboxApi: { status: async () => ({ available: false }) },
}))

import { APPS, appMessageKey, availableApps, findApp } from '@/apps/catalog'
import { appRouteGuard } from '@/router/appGuards'
import type { RouteLocationNormalized } from 'vue-router'

const routeTo = (appId: string) => ({ params: { appId } }) as unknown as RouteLocationNormalized

describe('apps catalog', () => {
  beforeEach(() => {
    runtime.modules = new Set()
    runtime.desktop = false
    runtime.platformLinks = false
    runtime.higgsfield = false
    runtime.gateway = false
    runtime.isAdmin = false
  })

  it('keeps ids unique and URL-safe', () => {
    const ids = APPS.map((app) => app.id)
    expect(new Set(ids).size).toBe(ids.length)
    for (const id of ids) expect(id).toMatch(/^[a-z0-9-]+$/)
  })

  it('maps dashed ids to camelCase message keys', () => {
    expect(appMessageKey('claude-code')).toBe('claudeCode')
    expect(appMessageKey('custom-tools')).toBe('customTools')
    expect(appMessageKey('telegram')).toBe('telegram')
  })

  it('leaves out apps whose module or flag is off', async () => {
    const ids = (await availableApps()).map((app) => app.id)
    expect(ids).not.toContain('telegram')
    expect(ids).not.toContain('higgsfield')
    expect(ids).not.toContain('desktop')
    expect(ids).not.toContain('nextcloud')
    expect(ids).not.toContain('claude-code')
    expect(ids).not.toContain('microsoft365')
    expect(ids).toContain('email')
    expect(ids).toContain('mcp')
  })

  it('shows an unconfigured OAuth provider to admins so they can set it up', async () => {
    runtime.isAdmin = true
    const ids = (await availableApps()).map((app) => app.id)
    expect(ids).toContain('microsoft365')
    expect(ids).toContain('dropbox')
    expect(ids).toContain('claude-code')
  })

  it('treats unknown and switched-off apps as unknown URLs', async () => {
    expect(await appRouteGuard(routeTo('nope'))).toEqual({ name: 'not-found' })
    expect(await appRouteGuard(routeTo('desktop'))).toEqual({ name: 'not-found' })
    runtime.desktop = true
    expect(await appRouteGuard(routeTo('desktop'))).toBe(true)
  })

  it('forwards an app with its own editor to that page', async () => {
    expect(findApp('widgets')?.to).toBe('/channels/widgets')
    expect(await appRouteGuard(routeTo('widgets'))).toBe('/channels/widgets')
  })
})
