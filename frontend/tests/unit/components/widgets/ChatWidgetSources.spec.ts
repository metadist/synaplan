import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const { sendWidgetMessage } = vi.hoisted(() => ({ sendWidgetMessage: vi.fn() }))
vi.mock('@/services/api/widgetsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/api/widgetsApi')>()),
  sendWidgetMessage,
}))

vi.mock('@heroicons/vue/24/outline', () => ({
  ChatBubbleLeftRightIcon: { template: '<svg />' },
  XMarkIcon: { template: '<svg />' },
  SunIcon: { template: '<svg />' },
  MoonIcon: { template: '<svg />' },
  ArrowUpIcon: { template: '<svg />' },
  ArrowDownTrayIcon: { template: '<svg />' },
  PaperClipIcon: { template: '<svg />' },
  ArrowsPointingOutIcon: { template: '<svg />' },
  ArrowsPointingInIcon: { template: '<svg />' },
  SparklesIcon: { template: '<svg />' },
  ArrowPathIcon: { template: '<svg />' },
  XCircleIcon: { template: '<svg />' },
  HandRaisedIcon: { template: '<svg />' },
  UserIcon: { template: '<svg />' },
  CpuChipIcon: { template: '<svg />' },
  DocumentIcon: { template: '<svg />' },
  PhotoIcon: { template: '<svg />' },
  DocumentTextIcon: { template: '<svg />' },
  MusicalNoteIcon: { template: '<svg />' },
  FilmIcon: { template: '<svg />' },
  TableCellsIcon: { template: '<svg />' },
  CodeBracketIcon: { template: '<svg />' },
  ArchiveBoxIcon: { template: '<svg />' },
  MagnifyingGlassIcon: { template: '<svg />' },
  ExclamationTriangleIcon: { template: '<svg />' },
  ArrowUpTrayIcon: { template: '<svg />' },
  BellAlertIcon: { template: '<svg />' },
}))

import ChatWidget from '@/components/widgets/ChatWidget.vue'

const ANSWER = 'The hotel pool is open until eight.'
const GROUP_KEY = 'WIDGET:wdg_787cca022ca90b45fb4d41c19048155c'

const sources = Array.from({ length: 5 }, (_, index) => ({
  n: index + 1,
  chunkId: `chunk-${index + 1}`,
  fileId: 0,
  fileName: '',
  groupKey: GROUP_KEY,
  score: 0.8,
  startLine: index === 0 ? 29 : 1,
  endLine: index === 0 ? 40 : 8,
}))

const TransitionStub = { template: '<div><slot /></div>', inheritAttrs: false }

function historyResponse(messages: unknown[]) {
  return new Response(JSON.stringify({ success: true, messages }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

function mountVisitor() {
  return mount(ChatWidget, {
    props: {
      widgetId: 'wdg_visitor',
      apiUrl: 'http://localhost:8000',
      testMode: false,
      openImmediately: true,
    },
    global: {
      stubs: {
        Teleport: { template: '<div><slot /></div>' },
        Transition: TransitionStub,
      },
    },
  })
}

function assertSourcesHidden(wrapper: ReturnType<typeof mountVisitor>) {
  expect(wrapper.text()).toContain(ANSWER)
  expect(wrapper.find('[data-testid="knowledge-sources"]').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('Show the passage')
  expect(wrapper.html()).not.toContain('WIDGET:wdg_')
}

describe('ChatWidget visitor sources', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('does not paint internal sources from history', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        if (String(url).includes('/history')) {
          return historyResponse([
            {
              id: 1,
              role: 'assistant',
              text: ANSWER,
              timestamp: 1_700_000_000,
              ragSources: sources,
            },
          ])
        }
        return new Response('{}', { status: 404 })
      })
    )

    const wrapper = mountVisitor()
    await flushPromises()

    assertSourcesHidden(wrapper)
  })

  it('does not paint internal sources from a live complete event', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        if (String(url).includes('/history')) return historyResponse([])
        return new Response('{}', { status: 404 })
      })
    )
    sendWidgetMessage.mockImplementation(async (_id, _text, _session, options) => {
      await options.onChunk?.(ANSWER)
      return { chatId: 9, text: ANSWER, ragSources: sources }
    })

    const wrapper = mountVisitor()
    await flushPromises()
    await wrapper.get('[data-testid="input-message"]').setValue('What time does the pool close?')
    await wrapper.get('[data-testid="btn-send"]').trigger('click')
    await flushPromises()

    assertSourcesHidden(wrapper)
  })
})
