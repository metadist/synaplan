import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import SetupStatusView from '@/views/SetupStatusView.vue'
import type { ServiceReadiness } from '@/services/featuresService'

const { freeApiStore, readinessStore } = vi.hoisted(() => ({
  freeApiStore: {
    status: null as { active: boolean } | null,
    isAvailable: vi.fn(() => false),
    load: vi.fn(),
  },
  readinessStore: {
    services: [] as ServiceReadiness[],
    loaded: true,
    loadFailed: false,
    acquire: vi.fn(),
    release: vi.fn(),
    refresh: vi.fn(),
  },
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => freeApiStore }))
vi.mock('@/stores/serviceReadiness', () => ({ useServiceReadinessStore: () => readinessStore }))

const i18nFor = (locale: 'en' | 'de' = 'en') =>
  createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: {
      en: asI18nSchema(loadAllMessages('en')),
      de: asI18nSchema(loadAllMessages('de')),
    },
  })

const service = (
  id: ServiceReadiness['id'],
  state: ServiceReadiness['state'],
  overrides: Partial<ServiceReadiness> = {}
): ServiceReadiness => ({
  id,
  name: id,
  state,
  unlocks: 'unlocks',
  effect: state === 'ready' ? null : 'effect',
  recovery: null,
  since: null,
  percent: null,
  model: null,
  minutes_left: null,
  profile: null,
  docs_anchor: null,
  ...overrides,
})

const mountView = (locale: 'en' | 'de' = 'en') =>
  mount(SetupStatusView, {
    global: {
      plugins: [i18nFor(locale)],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: {
          template:
            '<header data-testid="section-setup-status-header"><h1>{{ title }}</h1><p data-testid="text-setup-status-lead">{{ subtitle }}</p></header>',
          props: ['title', 'subtitle', 'icon'],
        },
      },
    },
  })

describe('SetupStatusView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    freeApiStore.status = null
    freeApiStore.isAvailable.mockReturnValue(false)
    readinessStore.loaded = true
    readinessStore.loadFailed = false
    readinessStore.services = [
      service('ollama', 'downloading', { percent: 42, model: 'gpt-oss:20b', minutes_left: 6 }),
      service('tika', 'ready'),
    ]
  })

  it('renders the calm lead line without Demo copy when the Demo module is off', () => {
    const wrapper = mountView()

    expect(wrapper.get('h1').text()).toBe('Setting up this computer')
    expect(wrapper.get('[data-testid="text-setup-status-lead"]').text()).toBe(
      'You can chat already.'
    )
    expect(wrapper.find('[data-testid="section-services"]').exists()).toBe(true)
    expect(readinessStore.acquire).toHaveBeenCalled()
    wrapper.unmount()
    expect(readinessStore.release).toHaveBeenCalled()
  })

  it('mentions the Synaplan Demo in the lead line while the Demo is active', () => {
    freeApiStore.isAvailable.mockReturnValue(true)
    freeApiStore.status = { active: true }
    const wrapper = mountView()

    expect(wrapper.get('[data-testid="text-setup-status-lead"]').text()).toBe(
      'You can chat already — the Synaplan Demo answers while the rest loads.'
    )
  })
})
