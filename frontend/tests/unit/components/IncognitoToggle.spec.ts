import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import IncognitoToggle from '@/components/IncognitoToggle.vue'
import { useIncognitoStore } from '@/stores/incognito'

const confirmMock = vi.fn()
vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({
    confirm: (...args: unknown[]) => confirmMock(...args),
  }),
}))

vi.mock('@/services/api/nativeHaptics', () => ({
  triggerHapticImpact: vi.fn(),
}))

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn().mockResolvedValue({}),
  getApiBaseUrl: () => 'http://localhost:8000',
}))

// The toggle only reads `messages.length` to decide whether ending the
// session needs a confirmation — a controllable stub keeps the spec hermetic.
const historyMessages: unknown[] = []
vi.mock('@/stores/history', () => ({
  useHistoryStore: () => ({ messages: historyMessages }),
}))

const mountToggle = () =>
  mount(IncognitoToggle, {
    global: {
      stubs: { Icon: true },
    },
  })

describe('IncognitoToggle', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    confirmMock.mockReset()
    historyMessages.length = 0
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('starts a session on click when inactive — no confirmation needed', async () => {
    const store = useIncognitoStore()
    const wrapper = mountToggle()

    await wrapper.find('[data-testid="btn-incognito-toggle"]').trigger('click')

    expect(store.active).toBe(true)
    expect(confirmMock).not.toHaveBeenCalled()
  })

  it('ends an empty session without asking for confirmation', async () => {
    const store = useIncognitoStore()
    store.startSession()

    const wrapper = mountToggle()
    await wrapper.find('[data-testid="btn-incognito-toggle"]').trigger('click')
    await flushPromises()

    expect(store.active).toBe(false)
    expect(confirmMock).not.toHaveBeenCalled()
  })

  it('asks for confirmation when the transcript is not empty and keeps the session on cancel', async () => {
    const store = useIncognitoStore()
    store.startSession()
    historyMessages.push({ id: 'm1' })
    confirmMock.mockResolvedValueOnce(false)

    const wrapper = mountToggle()
    await wrapper.find('[data-testid="btn-incognito-toggle"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledTimes(1)
    expect(store.active).toBe(true)
  })

  it('ends the session when the confirmation is accepted', async () => {
    const store = useIncognitoStore()
    store.startSession()
    historyMessages.push({ id: 'm1' })
    confirmMock.mockResolvedValueOnce(true)

    const wrapper = mountToggle()
    await wrapper.find('[data-testid="btn-incognito-toggle"]').trigger('click')
    await flushPromises()

    expect(store.active).toBe(false)
  })

  it('asks for confirmation when ephemeral files exist even without messages', async () => {
    const store = useIncognitoStore()
    store.startSession()
    store.registerFile(42)
    confirmMock.mockResolvedValueOnce(true)

    const wrapper = mountToggle()
    await wrapper.find('[data-testid="btn-incognito-toggle"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledTimes(1)
    expect(store.active).toBe(false)
  })

  it('reflects the active state via aria-pressed', async () => {
    const store = useIncognitoStore()
    const wrapper = mountToggle()

    expect(wrapper.find('[data-testid="btn-incognito-toggle"]').attributes('aria-pressed')).toBe(
      'false'
    )

    store.startSession()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="btn-incognito-toggle"]').attributes('aria-pressed')).toBe(
      'true'
    )
  })
})
