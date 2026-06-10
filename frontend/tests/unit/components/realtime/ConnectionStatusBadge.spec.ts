import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'

import ConnectionStatusBadge from '@/components/realtime/ConnectionStatusBadge.vue'
import { useRealtimeStore } from '@/stores/realtime'

vi.mock('@/services/realtime/RealtimeClient', () => ({
  RealtimeClient: vi.fn(),
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ realtime: { enabled: true, wsUrl: '' } }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      realtime: {
        status: {
          connected: 'Live',
          connecting: 'Connecting…',
          reconnecting: 'Reconnecting…',
          disconnected: 'Offline',
          disabled: 'Live updates off',
          error: 'Connection error',
        },
        tooltip: {
          connected: 'Realtime connection is healthy',
          connecting: 'Establishing realtime connection…',
          reconnecting: 'Realtime connection lost — retrying automatically',
          disconnected: 'Realtime connection is closed',
          disabled: 'Live updates are turned off in this environment.',
          error: 'Realtime connection failed: {message}',
        },
      },
    },
  },
})

function mountBadge(props: Record<string, unknown> = {}) {
  setActivePinia(createPinia())
  return mount(ConnectionStatusBadge, {
    props,
    global: {
      plugins: [i18n],
    },
  })
}

describe('ConnectionStatusBadge', () => {
  it('hides itself when realtime is disabled (default behaviour)', () => {
    const wrapper = mountBadge()
    const store = useRealtimeStore()
    store.state = 'disabled'
    return wrapper.vm.$nextTick().then(() => {
      expect(wrapper.find('[data-testid="comp-realtime-status"]').exists()).toBe(false)
    })
  })

  it('shows the disabled pill when showWhenDisabled is set', async () => {
    const wrapper = mountBadge({ showWhenDisabled: true })
    const store = useRealtimeStore()
    store.state = 'disabled'
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="comp-realtime-status"]').text()).toBe('Live updates off')
  })

  it('shows the connected pill when the WS is healthy', async () => {
    const wrapper = mountBadge()
    const store = useRealtimeStore()
    store.state = 'connected'
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="comp-realtime-status"]').text()).toBe('Live')
  })

  it('hides the connected pill when hideWhenConnected is set (sr-only)', async () => {
    const wrapper = mountBadge({ hideWhenConnected: true })
    const store = useRealtimeStore()
    store.state = 'connected'
    await wrapper.vm.$nextTick()
    // Still rendered for screen readers but visually hidden.
    expect(wrapper.find('[data-testid="comp-realtime-status"]').classes()).toContain('sr-only')
  })

  it('renders an error tooltip including the lastError message', async () => {
    const wrapper = mountBadge()
    const store = useRealtimeStore()
    store.state = 'error'
    store.lastError = 'token-invalid'
    await wrapper.vm.$nextTick()
    const badge = wrapper.find('[data-testid="comp-realtime-status"]')
    expect(badge.text()).toBe('Connection error')
    expect(badge.attributes('title')).toContain('token-invalid')
  })
})
