import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import ServiceReadinessPill from '@/components/chat/ServiceReadinessPill.vue'
import type { ServiceReadiness } from '@/services/featuresService'
import { SERVICES_READY_TOAST_SEEN_ID, SERVICES_STARTING_TOAST_SEEN_ID } from '@/tours'

const { authStore, readinessStore, seen } = vi.hoisted(() => ({
  authStore: {
    isAuthenticated: true,
    isAdmin: true,
  },
  readinessStore: {
    services: [] as ServiceReadiness[],
    acquire: vi.fn(),
    release: vi.fn(),
  },
  seen: {
    value: [] as string[],
  },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => authStore }))
vi.mock('@/stores/serviceReadiness', () => ({ useServiceReadinessStore: () => readinessStore }))
vi.mock('@/composables/useTour', () => ({ useTour: () => ({ seen }) }))

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

const mountPill = (locale: 'en' | 'de' = 'en') =>
  mount(ServiceReadinessPill, {
    global: {
      plugins: [i18nFor(locale)],
      stubs: {
        RouterLink: {
          template: '<a :href="to"><slot /></a>',
          props: ['to'],
        },
      },
    },
  })

describe('ServiceReadinessPill', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authStore.isAuthenticated = true
    authStore.isAdmin = true
    readinessStore.services = []
    seen.value = []
  })

  it('shows nothing when the services list is empty or only switched-off services exist', () => {
    const empty = mountPill()
    expect(empty.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)

    readinessStore.services = [service('collabora', 'off')]
    const offOnly = mountPill()
    expect(offOnly.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)
  })

  it('shows the progress pill while a service is starting or downloading, counting only running services', () => {
    readinessStore.services = [
      service('ollama', 'downloading', { percent: 42 }),
      service('qdrant', 'starting'),
      service('whisper', 'ready'),
      service('tts', 'ready'),
      service('tika', 'ready'),
      service('collabora', 'off'),
    ]

    const wrapper = mountPill()
    const pill = wrapper.get('[data-testid="pill-service-readiness"]')
    expect(pill.attributes('href')).toBe('/setup/status')
    expect(pill.attributes('data-kind')).toBe('progress')
    expect(wrapper.get('[data-testid="pill-service-readiness-label"]').text()).toBe(
      'Still setting up · 3 of 5 ready'
    )
    expect(wrapper.get('[data-testid="pill-service-readiness-short"]').text()).toBe('3/5')
  })

  it('turns amber when a service is unavailable or failed', () => {
    readinessStore.services = [
      service('qdrant', 'unavailable'),
      service('tika', 'ready'),
      service('collabora', 'off'),
    ]

    const wrapper = mountPill()
    const pill = wrapper.get('[data-testid="pill-service-readiness"]')
    expect(pill.attributes('data-kind')).toBe('attention')
    expect(wrapper.get('[data-testid="pill-service-readiness-label"]').text()).toBe(
      '1 needs attention'
    )
    expect(wrapper.get('[data-testid="pill-service-readiness-short"]').text()).toBe('1')
  })

  it('hides the pill once all running services are ready', () => {
    readinessStore.services = [
      service('qdrant', 'ready'),
      service('tika', 'ready'),
      service('collabora', 'off'),
    ]

    const wrapper = mountPill()
    expect(wrapper.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)
  })

  it('suppresses progress in later sessions once readiness was completed, but still shows attention', () => {
    seen.value = [SERVICES_READY_TOAST_SEEN_ID]
    readinessStore.services = [service('ollama', 'downloading'), service('tika', 'ready')]

    const progressWrapper = mountPill()
    expect(progressWrapper.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)

    // First setup, still in progress, shows progress:
    seen.value = [SERVICES_STARTING_TOAST_SEEN_ID]
    const firstSessionWrapper = mountPill()
    expect(firstSessionWrapper.find('[data-testid="pill-service-readiness"]').exists()).toBe(true)

    // A finished first setup that went through "starting" stays quiet on later restarts:
    seen.value = [SERVICES_STARTING_TOAST_SEEN_ID, SERVICES_READY_TOAST_SEEN_ID]
    const restartWrapper = mountPill()
    expect(restartWrapper.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)

    // Later session with an outage still shows the amber pill:
    seen.value = [SERVICES_READY_TOAST_SEEN_ID]
    readinessStore.services = [service('qdrant', 'failed'), service('tika', 'ready')]
    const outageWrapper = mountPill()
    expect(
      outageWrapper.get('[data-testid="pill-service-readiness"]').attributes('data-kind')
    ).toBe('attention')
  })

  it('stays hidden for non-admin users', () => {
    authStore.isAdmin = false
    readinessStore.services = [service('qdrant', 'unavailable')]

    const wrapper = mountPill()
    expect(wrapper.find('[data-testid="pill-service-readiness"]').exists()).toBe(false)
    expect(readinessStore.acquire).not.toHaveBeenCalled()
  })
})
