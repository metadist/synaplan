import { defineComponent, h, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { i18n } from '@/i18n'
import ErrorBoundary from '@/components/ErrorBoundary.vue'
import { resetDevPiniaContextRecovery } from '@/utils/devPiniaContextRecovery'

const PINIA_ERROR =
  '[🍍]: "getActivePinia()" was called but there was no active Pinia. Are you trying to use a store before calling "app.use(pinia)"?'

function child(message: string, failEveryTime: boolean) {
  const hits = { n: 0 }
  return defineComponent({
    name: 'PiniaProbeChild',
    setup() {
      hits.n += 1
      if (failEveryTime || hits.n === 1) throw new Error(message)
      return () => h('p', { 'data-testid': 'recovered' }, 'ok')
    },
  })
}

async function mountBoundary(slot: ReturnType<typeof child>) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  const wrapper = mount(ErrorBoundary, {
    slots: { default: slot },
    global: {
      plugins: [createPinia(), router, i18n],
      stubs: { ErrorView: { template: '<div data-testid="page-error" />' } },
    },
  })
  await router.isReady()
  await flushPromises()
  await nextTick()
  await flushPromises()
  return wrapper
}

describe('ErrorBoundary Pinia hot-update recovery', () => {
  let reloads = 0

  beforeEach(() => {
    reloads = 0
    resetDevPiniaContextRecovery(() => {
      reloads += 1
    })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })

  afterEach(() => {
    resetDevPiniaContextRecovery()
    vi.restoreAllMocks()
  })

  it('remounts the page after one Pinia context loss and does not show the fatal screen', async () => {
    const wrapper = await mountBoundary(child(PINIA_ERROR, false))

    expect(wrapper.find('[data-testid="comp-error-boundary"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="recovered"]').exists()).toBe(true)
    expect(reloads).toBe(0)
  })

  it('reloads when the remount still has no Pinia, instead of leaving the fatal screen up', async () => {
    const wrapper = await mountBoundary(child(PINIA_ERROR, true))

    expect(reloads).toBe(1)
    expect(wrapper.find('[data-testid="comp-error-boundary"]').exists()).toBe(false)
  })

  it('still shows the fatal screen for a real component error', async () => {
    const wrapper = await mountBoundary(child('component failed', true))

    expect(wrapper.find('[data-testid="comp-error-boundary"]').exists()).toBe(true)
    expect(reloads).toBe(0)
  })
})
