import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import ChatWidget from '@/components/widgets/ChatWidget.vue'

const mockSendWidgetMessage = vi.fn()

vi.mock('@/services/api/widgetsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/widgetsApi')>()
  return {
    ...actual,
    sendWidgetMessage: (...args: unknown[]) => mockSendWidgetMessage(...args),
  }
})

vi.mock('@/services/sseClient', () => ({
  subscribeToSession: vi.fn(() => ({ unsubscribe: vi.fn() })),
}))

vi.mock('@heroicons/vue/24/outline', () => ({
  ChatBubbleLeftRightIcon: { template: '<svg data-icon="chat" />' },
  XMarkIcon: { template: '<svg data-icon="x" />' },
  SunIcon: { template: '<svg data-icon="sun" />' },
  MoonIcon: { template: '<svg data-icon="moon" />' },
  PaperAirplaneIcon: { template: '<svg data-icon="send" />' },
  ArrowDownTrayIcon: { template: '<svg data-icon="download" />' },
  PaperClipIcon: { template: '<svg data-icon="clip" />' },
  ArrowsPointingOutIcon: { template: '<svg data-icon="expand" />' },
  ArrowsPointingInIcon: { template: '<svg data-icon="shrink" />' },
  SparklesIcon: { template: '<svg data-icon="sparkles" />' },
  ArrowPathIcon: { template: '<svg data-icon="arrow-path" />' },
  XCircleIcon: { template: '<svg data-icon="x-circle" />' },
  HandRaisedIcon: { template: '<svg data-icon="hand" />' },
  UserIcon: { template: '<svg data-icon="user" />' },
  CpuChipIcon: { template: '<svg data-icon="cpu" />' },
  DocumentIcon: { template: '<svg data-icon="document" />' },
  PhotoIcon: { template: '<svg data-icon="photo" />' },
  DocumentTextIcon: { template: '<svg data-icon="doc-text" />' },
  MusicalNoteIcon: { template: '<svg data-icon="music" />' },
  FilmIcon: { template: '<svg data-icon="film" />' },
  TableCellsIcon: { template: '<svg data-icon="table" />' },
  CodeBracketIcon: { template: '<svg data-icon="code" />' },
  ArchiveBoxIcon: { template: '<svg data-icon="archive" />' },
  MagnifyingGlassIcon: { template: '<svg data-icon="search" />' },
  ExclamationTriangleIcon: { template: '<svg data-icon="warning" />' },
  ArrowUpTrayIcon: { template: '<svg data-icon="upload" />' },
}))

const TransitionStub = {
  template: '<div><slot /></div>',
  inheritAttrs: false,
}

const baseProps = {
  widgetId: 'wdg_test123',
  apiUrl: 'http://localhost:8000',
}

function mountWidget(overrides = {}) {
  return mount(ChatWidget, {
    props: { ...baseProps, ...overrides },
    global: {
      stubs: {
        Teleport: true,
        Transition: TransitionStub,
      },
    },
  })
}

describe('ChatWidget — Session Mode', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify({ success: true, messages: [] }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      )
    )
  })

  describe('browser session mode (default)', () => {
    it('uses widgetId-only storage key by default', async () => {
      mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      const key = `synaplan_widget_session_${baseProps.widgetId}`
      expect(localStorage.getItem(key)).toBeTruthy()
    })

    it('does not create user-scoped storage key when sessionMode is browser', async () => {
      mountWidget({
        openImmediately: true,
        externalUserId: 'user_42',
        sessionMode: 'browser',
      })
      await flushPromises()
      await nextTick()

      const userKey = `synaplan_widget_session_${baseProps.widgetId}_user_42`
      const browserKey = `synaplan_widget_session_${baseProps.widgetId}`
      expect(localStorage.getItem(userKey)).toBeNull()
      expect(localStorage.getItem(browserKey)).toBeTruthy()
    })

    it('uses same session regardless of externalUserId when in browser mode', async () => {
      const wrapper1 = mountWidget({
        openImmediately: true,
        externalUserId: 'user_A',
        sessionMode: 'browser',
      })
      await flushPromises()
      await nextTick()
      const sessionA = localStorage.getItem(`synaplan_widget_session_${baseProps.widgetId}`)
      wrapper1.unmount()

      const wrapper2 = mountWidget({
        openImmediately: true,
        externalUserId: 'user_B',
        sessionMode: 'browser',
      })
      await flushPromises()
      await nextTick()
      const sessionB = localStorage.getItem(`synaplan_widget_session_${baseProps.widgetId}`)
      wrapper2.unmount()

      expect(sessionA).toBe(sessionB)
    })
  })

  describe('user session mode', () => {
    it('creates user-scoped storage key when sessionMode is user with externalUserId', async () => {
      mountWidget({
        openImmediately: true,
        externalUserId: 'user_42',
        sessionMode: 'user',
      })
      await flushPromises()
      await nextTick()

      const userKey = `synaplan_widget_session_${baseProps.widgetId}_user_42`
      expect(localStorage.getItem(userKey)).toBeTruthy()
    })

    it('falls back to browser-based key when sessionMode is user but no externalUserId', async () => {
      mountWidget({
        openImmediately: true,
        sessionMode: 'user',
      })
      await flushPromises()
      await nextTick()

      const browserKey = `synaplan_widget_session_${baseProps.widgetId}`
      expect(localStorage.getItem(browserKey)).toBeTruthy()
    })

    it('creates separate sessions for different users', async () => {
      const wrapper1 = mountWidget({
        openImmediately: true,
        externalUserId: 'user_A',
        sessionMode: 'user',
      })
      await flushPromises()
      await nextTick()
      wrapper1.unmount()

      const wrapper2 = mountWidget({
        openImmediately: true,
        externalUserId: 'user_B',
        sessionMode: 'user',
      })
      await flushPromises()
      await nextTick()
      wrapper2.unmount()

      const sessionA = localStorage.getItem(`synaplan_widget_session_${baseProps.widgetId}_user_A`)
      const sessionB = localStorage.getItem(`synaplan_widget_session_${baseProps.widgetId}_user_B`)
      expect(sessionA).toBeTruthy()
      expect(sessionB).toBeTruthy()
      expect(sessionA).not.toBe(sessionB)
    })
  })
})

describe('ChatWidget — Thinking Block Filtering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('strips completed thinking blocks from streamed responses', async () => {
    let capturedOnChunk: ((chunk: string) => void) | undefined
    mockSendWidgetMessage.mockImplementation(
      (_wid: string, _msg: string, _sid: string, opts: { onChunk: (chunk: string) => void }) => {
        capturedOnChunk = opts.onChunk
        return Promise.resolve({ chatId: 1 })
      }
    )

    const wrapper = mountWidget({ openImmediately: true, testMode: true })
    await flushPromises()
    await nextTick()

    const textarea = wrapper.find('[data-testid="input-message"]')
    await textarea.setValue('Hello')
    await wrapper.find('[data-testid="btn-send"]').trigger('click')
    await flushPromises()
    await nextTick()

    capturedOnChunk!('<think>internal reasoning</think>The actual answer.')
    await nextTick()

    const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
    const lastMessage = assistantMessages[assistantMessages.length - 1]
    expect(lastMessage.text()).not.toContain('internal reasoning')
    expect(lastMessage.text()).not.toContain('<think>')
    expect(lastMessage.text()).toContain('The actual answer.')
  })

  it('hides in-progress thinking blocks during streaming', async () => {
    let capturedOnChunk: ((chunk: string) => void) | undefined
    mockSendWidgetMessage.mockImplementation(
      (_wid: string, _msg: string, _sid: string, opts: { onChunk: (chunk: string) => void }) => {
        capturedOnChunk = opts.onChunk
        return Promise.resolve({ chatId: 1 })
      }
    )

    const wrapper = mountWidget({ openImmediately: true, testMode: true })
    await flushPromises()
    await nextTick()

    const textarea = wrapper.find('[data-testid="input-message"]')
    await textarea.setValue('Hello')
    await wrapper.find('[data-testid="btn-send"]').trigger('click')
    await flushPromises()
    await nextTick()

    capturedOnChunk!('<think>still thinking...')
    await nextTick()

    const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
    const lastMessage = assistantMessages[assistantMessages.length - 1]
    expect(lastMessage.text()).not.toContain('still thinking')
    expect(lastMessage.text()).not.toContain('<think>')
  })

  it('shows content after thinking block is closed', async () => {
    let capturedOnChunk: ((chunk: string) => void) | undefined
    mockSendWidgetMessage.mockImplementation(
      (_wid: string, _msg: string, _sid: string, opts: { onChunk: (chunk: string) => void }) => {
        capturedOnChunk = opts.onChunk
        return Promise.resolve({ chatId: 1 })
      }
    )

    const wrapper = mountWidget({ openImmediately: true, testMode: true })
    await flushPromises()
    await nextTick()

    const textarea = wrapper.find('[data-testid="input-message"]')
    await textarea.setValue('Hello')
    await wrapper.find('[data-testid="btn-send"]').trigger('click')
    await flushPromises()
    await nextTick()

    capturedOnChunk!('<think>reasoning step 1')
    capturedOnChunk!(' reasoning step 2</think>')
    capturedOnChunk!('Here is the answer.')
    await nextTick()

    const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
    const lastMessage = assistantMessages[assistantMessages.length - 1]
    expect(lastMessage.text()).not.toContain('reasoning step')
    expect(lastMessage.text()).toContain('Here is the answer.')
  })

  it('handles multiple thinking blocks in a single response', async () => {
    let capturedOnChunk: ((chunk: string) => void) | undefined
    mockSendWidgetMessage.mockImplementation(
      (_wid: string, _msg: string, _sid: string, opts: { onChunk: (chunk: string) => void }) => {
        capturedOnChunk = opts.onChunk
        return Promise.resolve({ chatId: 1 })
      }
    )

    const wrapper = mountWidget({ openImmediately: true, testMode: true })
    await flushPromises()
    await nextTick()

    const textarea = wrapper.find('[data-testid="input-message"]')
    await textarea.setValue('Complex question')
    await wrapper.find('[data-testid="btn-send"]').trigger('click')
    await flushPromises()
    await nextTick()

    capturedOnChunk!('<think>thought 1</think>Part 1. <think>thought 2</think>Part 2.')
    await nextTick()

    const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
    const lastMessage = assistantMessages[assistantMessages.length - 1]
    expect(lastMessage.text()).not.toContain('thought 1')
    expect(lastMessage.text()).not.toContain('thought 2')
    expect(lastMessage.text()).toContain('Part 1.')
    expect(lastMessage.text()).toContain('Part 2.')
  })

  it('renders normally when no thinking blocks are present', async () => {
    let capturedOnChunk: ((chunk: string) => void) | undefined
    mockSendWidgetMessage.mockImplementation(
      (_wid: string, _msg: string, _sid: string, opts: { onChunk: (chunk: string) => void }) => {
        capturedOnChunk = opts.onChunk
        return Promise.resolve({ chatId: 1 })
      }
    )

    const wrapper = mountWidget({ openImmediately: true, testMode: true })
    await flushPromises()
    await nextTick()

    const textarea = wrapper.find('[data-testid="input-message"]')
    await textarea.setValue('Simple question')
    await wrapper.find('[data-testid="btn-send"]').trigger('click')
    await flushPromises()
    await nextTick()

    capturedOnChunk!('A simple answer without reasoning.')
    await nextTick()

    const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
    const lastMessage = assistantMessages[assistantMessages.length - 1]
    expect(lastMessage.text()).toContain('A simple answer without reasoning.')
  })
})
