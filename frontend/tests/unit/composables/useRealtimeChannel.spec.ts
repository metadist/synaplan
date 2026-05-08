/* eslint-disable vue/one-component-per-file */
// Tests intentionally declare ad-hoc host components alongside the
// composable assertions — this is the standard `defineComponent` test
// pattern and does not violate the "one component per .vue file" rule
// the lint rule was designed for.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

interface FakeHandle {
  unsubscribe: ReturnType<typeof vi.fn>
}

const subscribeMock = vi.fn()

vi.mock('@/stores/realtime', () => ({
  useRealtimeStore: () => ({
    getOrCreateClient: () => ({
      subscribe: subscribeMock,
    }),
    state: 'connected',
    lastError: null,
  }),
}))

import { useRealtimeChannel } from '@/composables/useRealtimeChannel'

describe('useRealtimeChannel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    subscribeMock.mockReset()
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  function buildHostComponent(
    channel: string | ReturnType<typeof ref<string | null>>,
    onPublication: (event: unknown) => void
  ) {
    return defineComponent({
      setup() {
        const ctx = useRealtimeChannel(channel as never, { onPublication })
        return () => h('div', { 'data-state': ctx.state })
      },
    })
  }

  it('subscribes to the channel on mount and unsubscribes on unmount', async () => {
    const handle: FakeHandle = { unsubscribe: vi.fn() }
    subscribeMock.mockResolvedValue(handle)

    const wrapper = mount(buildHostComponent('widget:operators.w_1', () => {}))
    await flushPromises()

    expect(subscribeMock).toHaveBeenCalledWith('widget:operators.w_1', expect.any(Object))

    wrapper.unmount()
    expect(handle.unsubscribe).toHaveBeenCalledOnce()
  })

  it('forwards publications and stores the latest event', async () => {
    let captured: unknown = null
    const handle: FakeHandle = { unsubscribe: vi.fn() }
    subscribeMock.mockImplementation(async (_, opts: { onPublication: (e: unknown) => void }) => {
      // Simulate two publications arriving from the wire.
      queueMicrotask(() => {
        opts.onPublication({ type: 'evt', ts: 1, data: { msg: 'a' } })
        opts.onPublication({ type: 'evt', ts: 2, data: { msg: 'b' } })
      })
      return handle
    })

    mount(
      buildHostComponent('widget:operators.w_1', (event) => {
        captured = event
      })
    )
    await flushPromises()

    expect(captured).toEqual({ type: 'evt', ts: 2, data: { msg: 'b' } })
  })

  it('resubscribes when the channel ref changes', async () => {
    const handle: FakeHandle = { unsubscribe: vi.fn() }
    subscribeMock.mockResolvedValue(handle)

    const channel = ref<string | null>('widget:operators.w_1')
    mount(buildHostComponent(channel, () => {}))
    await flushPromises()

    channel.value = 'widget:operators.w_2'
    await flushPromises()

    expect(subscribeMock).toHaveBeenCalledTimes(2)
    expect(subscribeMock.mock.calls[1][0]).toBe('widget:operators.w_2')
    expect(handle.unsubscribe).toHaveBeenCalled()
  })

  it('skips subscribing when channel ref is null', async () => {
    subscribeMock.mockResolvedValue({ unsubscribe: vi.fn() })

    const channel = ref<string | null>(null)
    mount(buildHostComponent(channel, () => {}))
    await flushPromises()

    expect(subscribeMock).not.toHaveBeenCalled()
  })

  it('captures errors from subscribe()', async () => {
    subscribeMock.mockRejectedValue(new Error('auth-failed'))
    const errors: string[] = []

    const Host = defineComponent({
      setup() {
        const ctx = useRealtimeChannel('widget:operators.w_1', {
          onPublication: () => {},
          onError: (msg) => errors.push(msg),
        })
        return () => h('div', { 'data-error': ctx.error.value })
      },
    })

    mount(Host)
    await flushPromises()

    expect(errors).toEqual(['auth-failed'])
  })
})
