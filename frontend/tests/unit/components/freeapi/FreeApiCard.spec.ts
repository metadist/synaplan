import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import FreeApiCard from '@/components/freeapi/FreeApiCard.vue'
import { i18n } from '@/i18n'

const { store, configStore, confirm, success, error } = vi.hoisted(() => ({
  store: {
    status: null as Record<string, unknown> | null,
    busy: false,
    leave: vi.fn(),
  },
  configStore: { setup: { chatReady: false } },
  confirm: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => store }))
vi.mock('@/stores/config', () => ({ useConfigStore: () => configStore }))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm }) }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))

const t = (key: string, named: Record<string, unknown> = {}) => i18n.global.t(key, named)

function mountCard(props: { bannerHidden?: boolean } = {}) {
  return mount(FreeApiCard, {
    props: { open: true, ...props },
    attachTo: document.body,
    global: {
      stubs: {
        Teleport: true,
        RouterLink: { template: '<a :data-to="JSON.stringify(to)"><slot /></a>', props: ['to'] },
      },
    },
  })
}

describe('FreeApiCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    i18n.global.locale.value = 'en'
    store.busy = false
    configStore.setup.chatReady = false
    store.status = {
      active: true,
      canManage: true,
      server: 'web.synaplan.com',
      expiresAt: null,
      daysLeft: null,
      activatedAt: null,
      activatedBy: null,
      installId: null,
      today: null,
      refusal: null,
    }
    confirm.mockResolvedValue(true)
    store.leave.mockResolvedValue({ keyRevoked: true })
  })

  it('names the server, the sharing and the way out for an admin', () => {
    const wrapper = mountCard()

    expect(wrapper.text()).toContain(t('freeApi.card.lead', { server: 'web.synaplan.com' }))
    expect(wrapper.text()).toContain(t('freeApi.card.shared'))
    expect(wrapper.find('[data-testid="freeapi-card-today"]').text()).toBe(
      t('freeApi.card.todayUnknown')
    )
    expect(wrapper.get('[data-testid="freeapi-card-ollama"]').attributes('data-to')).toBe(
      JSON.stringify({ path: '/admin/setup', query: { section: 'local-ai' } })
    )
    expect(wrapper.find('[data-testid="freeapi-card-leave"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('leaves after the confirmation and says what came back', async () => {
    const wrapper = mountCard()
    await wrapper.get('[data-testid="freeapi-card-leave"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ danger: true }))
    expect(store.leave).toHaveBeenCalled()
    expect(success).toHaveBeenCalledWith(t('freeApi.leave.done'))
    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })

  it('says chat keeps working when an own provider takes over', async () => {
    store.leave.mockImplementation(async () => {
      configStore.setup.chatReady = true
      return { keyRevoked: true }
    })
    const wrapper = mountCard()
    await wrapper.get('[data-testid="freeapi-card-leave"]').trigger('click')
    await flushPromises()

    expect(success).toHaveBeenCalledWith(t('freeApi.leave.doneReady'))
    wrapper.unmount()
  })

  it('says when the key is withdrawn later', async () => {
    store.leave.mockResolvedValue({ keyRevoked: false })
    const wrapper = mountCard()
    await wrapper.get('[data-testid="freeapi-card-leave"]').trigger('click')
    await flushPromises()

    expect(success).toHaveBeenCalledWith(
      `${t('freeApi.leave.done')} ${t('freeApi.leave.revokeLater')}`
    )
    wrapper.unmount()
  })

  it('changes nothing when the confirmation is declined', async () => {
    confirm.mockResolvedValue(false)
    const wrapper = mountCard()
    await wrapper.get('[data-testid="freeapi-card-leave"]').trigger('click')
    await flushPromises()

    expect(store.leave).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('does not claim success when leaving failed', async () => {
    store.leave.mockRejectedValue(new Error('down'))
    const wrapper = mountCard()
    await wrapper.get('[data-testid="freeapi-card-leave"]').trigger('click')
    await flushPromises()

    expect(error).toHaveBeenCalledWith(t('freeApi.leave.failed'))
    expect(success).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows a member the facts, no setup or leave action', () => {
    store.status = { ...store.status!, canManage: false }
    const wrapper = mountCard()

    expect(wrapper.text()).toContain(t('freeApi.card.memberHint'))
    expect(wrapper.find('[data-testid="freeapi-card-leave"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="freeapi-card-own-keys"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('closes on Escape', () => {
    const wrapper = mountCard()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })

  it('offers the banner back only while it is hidden', async () => {
    const shown = mountCard()
    expect(shown.find('[data-testid="freeapi-card-show-banner"]').exists()).toBe(false)
    shown.unmount()

    const hidden = mountCard({ bannerHidden: true })
    await hidden.get('[data-testid="freeapi-card-show-banner"]').trigger('click')
    expect(hidden.emitted('showBanner')).toHaveLength(1)
    hidden.unmount()
  })
})
