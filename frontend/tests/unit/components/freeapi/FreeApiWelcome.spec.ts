import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import FreeApiWelcome from '@/components/freeapi/FreeApiWelcome.vue'
import { i18n } from '@/i18n'
import { ApiError } from '@/services/api/httpClient'

const { store, readinessStore, push, success } = vi.hoisted(() => ({
  store: {
    status: null as { server: string } | null,
    busy: false,
    load: vi.fn(),
    start: vi.fn(),
  },
  readinessStore: {
    services: [] as unknown[],
    acquire: vi.fn(),
    release: vi.fn(),
  },
  push: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => store }))
vi.mock('@/stores/serviceReadiness', () => ({ useServiceReadinessStore: () => readinessStore }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))
vi.mock('@/composables/useNotification', () => ({ useNotification: () => ({ success }) }))

const t = (key: string, params: Record<string, unknown> = {}) => i18n.global.t(key, params)

describe('FreeApiWelcome', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    i18n.global.locale.value = 'en'
    store.status = { server: 'web.synaplan.com' }
    store.busy = false
    store.start.mockResolvedValue(undefined)
    readinessStore.services = []
  })

  it('preselects the Demo and shows the consent sentence with the real server', async () => {
    const wrapper = mount(FreeApiWelcome)
    await flushPromises()

    expect(store.load).toHaveBeenCalled()
    expect(
      wrapper.get<HTMLInputElement>('[data-testid="freeapi-choice-demo"] input').element.checked
    ).toBe(true)
    expect(wrapper.get('[data-testid="freeapi-consent"]').text()).toBe(
      t('freeApi.consent', { server: 'web.synaplan.com' })
    )
    expect(wrapper.get('[data-testid="freeapi-welcome-primary"]').text()).toBe(
      t('freeApi.welcome.primary.demo')
    )
  })

  it('names the Demo server generically until its status has loaded', () => {
    store.status = null
    const wrapper = mount(FreeApiWelcome)

    expect(wrapper.get('[data-testid="freeapi-consent"]').text()).toBe(
      t('freeApi.consent', { server: t('freeApi.serverFallback') })
    )
  })

  it('starts the Demo in the interface language and confirms it', async () => {
    const wrapper = mount(FreeApiWelcome)
    await wrapper.get('[data-testid="freeapi-welcome-primary"]').trigger('click')
    await flushPromises()

    expect(store.start).toHaveBeenCalledWith('en')
    expect(success).toHaveBeenCalledWith(t('freeApi.started'))
    expect(wrapper.find('[data-testid="freeapi-start-error"]').exists()).toBe(false)
  })

  it('says in plain words why the Demo was not started', async () => {
    store.start.mockRejectedValue(new ApiError(503, 'full', 'freeapi_full_today'))
    const wrapper = mount(FreeApiWelcome)
    await wrapper.get('[data-testid="freeapi-welcome-primary"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="freeapi-start-error"]').text()).toBe(
      t('freeApi.startError.fullToday')
    )
    expect(success).not.toHaveBeenCalled()
  })

  it('sends Ollama and own-key choices to the matching setup section', async () => {
    const wrapper = mount(FreeApiWelcome)

    await wrapper.get('[data-testid="freeapi-choice-ollama"] input').setValue(true)
    expect(wrapper.find('[data-testid="freeapi-consent"]').exists()).toBe(false)
    await wrapper.get('[data-testid="freeapi-welcome-primary"]').trigger('click')
    expect(push).toHaveBeenLastCalledWith({ path: '/admin/setup', query: { section: 'local-ai' } })

    await wrapper.get('[data-testid="freeapi-choice-key"] input').setValue(true)
    await wrapper.get('[data-testid="freeapi-welcome-primary"]').trigger('click')
    expect(push).toHaveBeenLastCalledWith({ path: '/admin/setup', query: { section: 'providers' } })
    expect(store.start).not.toHaveBeenCalled()
  })

  it('drops a start error once another way is picked', async () => {
    store.start.mockRejectedValue(new ApiError(502, 'down', 'freeapi_unreachable'))
    const wrapper = mount(FreeApiWelcome)
    await wrapper.get('[data-testid="freeapi-welcome-primary"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="freeapi-start-error"]').exists()).toBe(true)

    await wrapper.get('[data-testid="freeapi-choice-key"] input').setValue(true)

    expect(wrapper.find('[data-testid="freeapi-start-error"]').exists()).toBe(false)
  })

  it('shows the Ollama download progress on the Ollama tile from the readiness list', () => {
    readinessStore.services = [
      {
        id: 'ollama',
        name: 'Ollama (local AI)',
        state: 'downloading',
        unlocks: '',
        effect: null,
        recovery: null,
        since: null,
        percent: 42,
        model: 'gpt-oss:20b',
        minutes_left: 6,
        profile: 'local-ai',
        docs_anchor: 'modules/local-ai',
      },
    ]
    const wrapper = mount(FreeApiWelcome)

    expect(readinessStore.acquire).toHaveBeenCalled()
    expect(wrapper.get('[data-testid="freeapi-ollama-badge"]').text()).toBe('Downloading · 42 %')
    wrapper.unmount()
    expect(readinessStore.release).toHaveBeenCalled()
  })
})
