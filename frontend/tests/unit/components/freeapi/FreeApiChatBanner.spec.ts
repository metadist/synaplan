import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import FreeApiChatBanner from '@/components/freeapi/FreeApiChatBanner.vue'
import { i18n } from '@/i18n'

interface StatusStub {
  active: boolean
  canManage: boolean
  daysLeft: number | null
  refusal: string | null
  today: { messagesRemaining: number; messagesLimit: number } | null
}

const { store } = vi.hoisted(() => ({
  store: {
    status: null as StatusStub | null,
    available: true,
    isAvailable: () => store.available,
    load: vi.fn(),
  },
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => store }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 7 } }) }))

const t = (key: string, named: Record<string, unknown> = {}) => i18n.global.t(key, named)
const STORAGE_KEY = 'synaplan_freeapi_banner_hidden_7'

function mountBanner(props: { streaming?: boolean } = {}) {
  return mount(FreeApiChatBanner, {
    props,
    global: {
      stubs: {
        RouterLink: { template: '<a :data-to="JSON.stringify(to)"><slot /></a>', props: ['to'] },
        FreeApiCard: {
          template: '<div v-if="open" data-testid="card-stub" />',
          props: ['open'],
        },
      },
    },
  })
}

describe('FreeApiChatBanner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    i18n.global.locale.value = 'en'
    store.available = true
    store.status = {
      active: true,
      canManage: true,
      daysLeft: 11,
      refusal: null,
      today: { messagesRemaining: 27, messagesLimit: 40 },
    }
  })

  it('is absent while the Demo is off or the module is not configured', async () => {
    store.status = { ...store.status!, active: false }
    expect(mountBanner().find('[data-testid="freeapi-banner-host"]').exists()).toBe(false)

    store.status = { ...store.status, active: true }
    store.available = false
    expect(mountBanner().find('[data-testid="freeapi-banner-host"]').exists()).toBe(false)
  })

  it("says it is shared and how many of today's messages are left", async () => {
    const wrapper = mountBanner()
    await flushPromises()

    expect(store.load).toHaveBeenCalled()
    const text = wrapper.get('[data-testid="freeapi-banner"]').text()
    expect(text).toContain(t('freeApi.banner.shared'))
    expect(text).toContain(t('freeApi.banner.left', { remaining: 27, limit: 40 }))
    expect(text).toContain(t('freeApi.banner.ownSetup'))
    expect(text).not.toContain('Ends in')
    expect(wrapper.get('[data-testid="freeapi-banner-own-keys"]').attributes('data-to')).toBe(
      JSON.stringify({ path: '/admin/setup', query: { section: 'providers' } })
    )
  })

  it('names the end in the last days and the reason when the server refuses', async () => {
    store.status = { ...store.status!, daysLeft: 2 }
    expect(mountBanner().text()).toContain(i18n.global.t('freeApi.banner.endsSoon', { days: 2 }, 2))

    store.status = { ...store.status, refusal: 'freeapi_paused' }
    expect(mountBanner().text()).toContain(t('freeApi.banner.unavailable'))

    store.status = { ...store.status, refusal: 'freeapi_expired' }
    expect(mountBanner().text()).toContain(t('freeApi.banner.expired'))
  })

  it('offers a member no setup action, only the details', () => {
    store.status = { ...store.status!, canManage: false }
    const wrapper = mountBanner()

    expect(wrapper.find('[data-testid="freeapi-banner-own-keys"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(t('freeApi.banner.ownSetupMember'))
    expect(wrapper.find('[data-testid="freeapi-banner-details"]').exists()).toBe(true)
  })

  it('collapses into a pill that is remembered and still opens the card', async () => {
    const wrapper = mountBanner()
    await wrapper.get('[data-testid="freeapi-banner-hide"]').trigger('click')

    expect(localStorage.getItem(STORAGE_KEY)).toBe('1')
    expect(wrapper.find('[data-testid="freeapi-banner"]').exists()).toBe(false)
    const pill = wrapper.get('[data-testid="freeapi-pill"]')
    expect(pill.text()).toBe(t('freeApi.banner.pill', { remaining: 27 }))

    await pill.trigger('click')
    expect(wrapper.find('[data-testid="card-stub"]').exists()).toBe(true)

    const again = mountBanner()
    await flushPromises()
    expect(again.find('[data-testid="freeapi-pill"]').exists()).toBe(true)
  })

  it("re-reads today's use when an answer has finished", async () => {
    const wrapper = mountBanner({ streaming: true })
    await flushPromises()
    store.load.mockClear()

    await wrapper.setProps({ streaming: false })

    expect(store.load).toHaveBeenCalledTimes(1)
  })
})
