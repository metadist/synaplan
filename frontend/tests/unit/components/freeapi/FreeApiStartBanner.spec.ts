import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FreeApiStartBanner from '@/components/freeapi/FreeApiStartBanner.vue'
import { i18n } from '@/i18n'

const { store } = vi.hoisted(() => ({
  store: {
    status: null as { server: string | null } | null,
    busy: false,
    load: vi.fn(),
  },
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => store }))

const t = (key: string, named: Record<string, unknown> = {}) => i18n.global.t(key, named)

function mountBanner(props: { errorKey?: string } = {}) {
  return mount(FreeApiStartBanner, {
    props,
    global: {
      stubs: {
        RouterLink: { template: '<a :data-to="JSON.stringify(to)"><slot /></a>', props: ['to'] },
      },
    },
  })
}

describe('FreeApiStartBanner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    i18n.global.locale.value = 'en'
    store.status = { server: 'web.synaplan.com' }
    store.busy = false
  })

  it('says the first message starts the Demo and where messages go', () => {
    const text = mountBanner().get('[data-testid="freeapi-start-banner"]').text()

    expect(store.load).toHaveBeenCalled()
    expect(text).toContain(t('freeApi.startOnSend.lead'))
    expect(text).toContain(t('freeApi.consent', { server: 'web.synaplan.com' }))
  })

  it('names the fallback server before the status arrived', () => {
    store.status = null
    const consent = mountBanner().get('[data-testid="freeapi-consent"]').text()

    expect(consent).toBe(t('freeApi.consent', { server: t('freeApi.serverFallback') }))
  })

  it('links to Ollama and to an own key in the setup', () => {
    const wrapper = mountBanner()

    expect(wrapper.get('[data-testid="freeapi-start-ollama"]').attributes('data-to')).toBe(
      JSON.stringify({ path: '/admin/setup', query: { section: 'local-ai' } })
    )
    expect(wrapper.get('[data-testid="freeapi-start-key"]').attributes('data-to')).toBe(
      JSON.stringify({ path: '/admin/setup', query: { section: 'providers' } })
    )
  })

  it('says it is starting while the Demo starts', () => {
    store.busy = true
    const text = mountBanner().text()

    expect(text).toContain(t('freeApi.startOnSend.starting'))
    expect(text).not.toContain(t('freeApi.startOnSend.lead'))
  })

  it('says why the Demo did not start and that the message was not sent', () => {
    const wrapper = mountBanner({ errorKey: 'freeApi.startError.fullToday' })
    const error = wrapper.get('[data-testid="freeapi-start-error"]')

    expect(error.attributes('role')).toBe('alert')
    expect(error.text()).toContain(t('freeApi.startError.fullToday'))
    expect(error.text()).toContain(t('freeApi.startOnSend.notSent'))
  })

  it('shows no error line without an error', () => {
    expect(mountBanner().find('[data-testid="freeapi-start-error"]').exists()).toBe(false)
  })
})
