import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useMemoriesStore } from '@/stores/userMemories'

/**
 * The message area keeps the newest message above the composer while its own
 * height changes: the native shell shrinks the chat column by the keyboard's
 * overlap on every frame, a mobile browser shrinks the viewport, the composer
 * grows by a line. One ResizeObserver on the container covers all of them.
 */

const observers = vi.hoisted(() => ({
  callbacks: [] as ResizeObserverCallback[],
  observed: [] as Element[],
  disconnected: 0,
}))

vi.stubGlobal(
  'ResizeObserver',
  class {
    constructor(callback: ResizeObserverCallback) {
      observers.callbacks.push(callback)
    }
    observe(target: Element): void {
      observers.observed.push(target)
    }
    unobserve(): void {}
    disconnect(): void {
      observers.disconnected += 1
    }
  }
)

vi.mock('@/services/api/httpClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/httpClient')>()
  return { ...actual, httpClient: vi.fn(async () => ({})) }
})

const { default: ChatView } = await import('@/views/ChatView.vue')

let wrapper: VueWrapper | null = null

async function mountChat(): Promise<HTMLElement> {
  useAuthStore().user = {
    id: 7,
    email: 'ada@example.test',
    level: 'PRO',
    emailVerified: true,
    isAdmin: false,
  } as ReturnType<typeof useAuthStore>['user']
  useChatsStore().activeChatId = 42
  useChatsStore().conversationAccess = 'owner'
  vi.spyOn(useHistoryStore(), 'loadMessages').mockResolvedValue(undefined)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: ChatView }],
  })
  await router.push('/')
  await router.isReady()
  wrapper = shallowMount(ChatView, {
    global: { plugins: [router], stubs: { MainLayout: { template: '<div><slot /></div>' } } },
  })
  await flushPromises()
  useMemoriesStore().memories = []
  useFeedbackStore().feedbacks = []
  const container = wrapper.get('[data-testid="section-messages"]').element as HTMLElement
  // happy-dom has no layout: give the list more content than room.
  Object.defineProperty(container, 'scrollHeight', { configurable: true, value: 2400 })
  return container
}

function fireResize(): void {
  for (const callback of observers.callbacks) {
    callback([], {} as ResizeObserver)
  }
}

describe('ChatView keyboard pin', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    observers.callbacks.length = 0
    observers.observed.length = 0
    observers.disconnected = 0
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
  })

  it('observes the message area and re-pins it to the bottom when its height changes', async () => {
    const container = await mountChat()
    expect(observers.observed).toContain(container)

    container.scrollTop = 0
    fireResize()
    expect(container.scrollTop).toBe(2400)
  })

  it('leaves a reader who scrolled up where they are', async () => {
    const container = await mountChat()
    container.scrollTop = 0
    // A user scroll away from the bottom turns the auto-scroll off.
    Object.defineProperty(container, 'clientHeight', { configurable: true, value: 600 })
    container.scrollTop = 500
    container.dispatchEvent(new Event('scroll'))
    await flushPromises()

    container.scrollTop = 500
    fireResize()
    expect(container.scrollTop).toBe(500)
  })

  it('keeps following the bottom when the scroll event of a pin arrives a frame late', async () => {
    const container = await mountChat()
    Object.defineProperty(container, 'clientHeight', { configurable: true, value: 600 })
    // Keyboard frame 1: the pin lands at 2400 - 600.
    Object.defineProperty(container, 'scrollHeight', { configurable: true, value: 2400 })
    fireResize()
    // Keyboard frame 2 shrinks the container again before frame 1's scroll
    // event is delivered; the position it reports is 60px short of the new
    // bottom and differs from the value last set.
    Object.defineProperty(container, 'clientHeight', { configurable: true, value: 540 })
    container.scrollTop = 1800
    container.dispatchEvent(new Event('scroll'))
    await flushPromises()

    container.scrollTop = 0
    fireResize()
    expect(container.scrollTop).toBe(2400)
  })

  it('disconnects the observer when the view unmounts', async () => {
    await mountChat()
    wrapper?.unmount()
    wrapper = null
    expect(observers.disconnected).toBe(1)
  })
})
