import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import SidebarPanelFooter from '@/components/sidebar/SidebarPanelFooter.vue'

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isAuthenticated: true,
    isAdmin: true,
    isPro: true,
    user: { email: 'admin@synaplan.com' },
  }),
}))
vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    billing: { enabled: false },
    auth: { registrationEnabled: false },
    apiBaseUrl: '',
  }),
}))
vi.mock('@/stores/smartSearch', () => ({
  useSmartSearchStore: () => ({ open: vi.fn() }),
}))
vi.mock('@/composables/useAuth', () => ({
  useAuth: () => ({ logout: vi.fn(), isImpersonating: false }),
}))
vi.mock('@/services/api/nativeServer', () => ({ isPurchaseAllowed: () => false }))
vi.mock('@/services/api/nativeHaptics', () => ({ triggerHapticImpact: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

const i18nFor = (locale: 'en' | 'de') =>
  createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: {
      en: asI18nSchema(loadAllMessages('en')),
      de: asI18nSchema(loadAllMessages('de')),
    },
  })

async function openMenu(locale: 'en' | 'de' = 'en') {
  document.body.innerHTML = '<div id="app"></div>'
  const wrapper = mount(SidebarPanelFooter, {
    attachTo: '#app',
    global: {
      plugins: [i18nFor(locale)],
      stubs: { RouterLink: { template: '<a><slot /></a>' } },
    },
  })
  await wrapper.get('[data-testid="btn-sidebar-v2-user"]').trigger('click')
  await flushPromises()
  return wrapper
}

const menuLink = (testid: string): HTMLElement => {
  const el = document.querySelector<HTMLElement>(`[data-testid="${testid}"]`)
  if (!el) throw new Error(`menu link ${testid} not rendered`)
  return el
}

describe('SidebarPanelFooter account menu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('links the sovereign site to teams and the main site to IT, both in a new tab', async () => {
    await openMenu()

    const eu = menuLink('btn-sidebar-v2-website-eu')
    expect(eu.getAttribute('href')).toBe('https://www.synaplan.eu/')
    expect(eu.getAttribute('target')).toBe('_blank')
    expect(eu.getAttribute('rel')).toContain('noopener')
    expect(eu.textContent).toContain('Teams & public sector')
    expect(eu.textContent).toContain('synaplan.eu')

    const tech = menuLink('btn-sidebar-v2-website-tech')
    expect(tech.getAttribute('href')).toBe('https://www.synaplan.com/de')
    expect(tech.getAttribute('target')).toBe('_blank')
    expect(tech.textContent).toContain('IT & developers')
    expect(tech.textContent).toContain('synaplan.com')
  })

  it('labels the two sites by audience in German too', async () => {
    await openMenu('de')

    expect(menuLink('btn-sidebar-v2-website-eu').textContent).toContain('Fachbereiche & Verwaltung')
    expect(menuLink('btn-sidebar-v2-website-tech').textContent).toContain('IT & Entwicklung')
  })
})
