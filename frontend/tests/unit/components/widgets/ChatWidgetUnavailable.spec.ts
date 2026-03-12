import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import ChatWidget from '@/components/widgets/ChatWidget.vue'
import { WidgetUnavailableError } from '@/services/api/widgetsApi'

const mockSendWidgetMessage = vi.fn()
const mockUploadWidgetFile = vi.fn()

vi.mock('@/services/api/widgetsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/widgetsApi')>()
  return {
    ...actual,
    sendWidgetMessage: (...args: unknown[]) => mockSendWidgetMessage(...args),
    uploadWidgetFile: (...args: unknown[]) => mockUploadWidgetFile(...args),
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

const requiredProps = {
  widgetId: 'wdg_test123',
  apiUrl: 'http://localhost:8000',
  testMode: true,
}

const TransitionStub = {
  template: '<div><slot /></div>',
  inheritAttrs: false,
}

function mountWidget(overrides = {}) {
  return mount(ChatWidget, {
    props: { ...requiredProps, ...overrides },
    global: {
      stubs: {
        Teleport: true,
        Transition: TransitionStub,
      },
    },
  })
}

async function openWidgetAndTypeMessage(wrapper: ReturnType<typeof mountWidget>, text = 'Hello') {
  const openBtn = wrapper.find('[data-testid="btn-open"]')
  if (openBtn.exists()) {
    await openBtn.trigger('click')
    await nextTick()
  }
  const textarea = wrapper.find('[data-testid="input-message"]')
  await textarea.setValue(text)
  return textarea
}

describe('ChatWidget — Widget Unavailable', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('sendWidgetMessage rejects with WidgetUnavailableError', () => {
    it('shows unavailable message and disables input', async () => {
      mockSendWidgetMessage.mockRejectedValueOnce(new WidgetUnavailableError(404))

      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      await openWidgetAndTypeMessage(wrapper, 'Test message')

      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const assistantMessages = wrapper.findAll('[data-testid="message-assistant"]')
      const lastAssistant = assistantMessages[assistantMessages.length - 1]
      expect(lastAssistant?.text()).toContain('Chat is currently unavailable')

      const textarea = wrapper.find('[data-testid="input-message"]')
      expect((textarea.element as HTMLTextAreaElement).disabled).toBe(true)
    })

    it('shows unavailable message only once on repeated failures', async () => {
      mockSendWidgetMessage.mockRejectedValue(new WidgetUnavailableError(503))

      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      await openWidgetAndTypeMessage(wrapper, 'First message')
      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const unavailableMessages = wrapper
        .findAll('[data-testid="message-assistant"]')
        .filter((el) => el.text().includes('Chat is currently unavailable'))
      expect(unavailableMessages.length).toBe(1)

      const textarea = wrapper.find('[data-testid="input-message"]')
      expect((textarea.element as HTMLTextAreaElement).disabled).toBe(true)
    })

    it('does not show generic error message on WidgetUnavailableError', async () => {
      mockSendWidgetMessage.mockRejectedValueOnce(new WidgetUnavailableError(404))

      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      await openWidgetAndTypeMessage(wrapper, 'Test')
      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const allText = wrapper.text()
      expect(allText).not.toContain('Sorry, I encountered an error')
      expect(allText).toContain('Chat is currently unavailable')
    })
  })

  describe('input controls are disabled when widget is unavailable', () => {
    it('disables the textarea with unavailable placeholder', async () => {
      mockSendWidgetMessage.mockRejectedValueOnce(new WidgetUnavailableError(404))

      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      await openWidgetAndTypeMessage(wrapper, 'Test')
      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const textarea = wrapper.find('[data-testid="input-message"]')
      expect((textarea.element as HTMLTextAreaElement).disabled).toBe(true)
      expect((textarea.element as HTMLTextAreaElement).placeholder).toBe('Chat unavailable')
    })

    it('disables the attach button when widget is unavailable', async () => {
      mockSendWidgetMessage.mockRejectedValueOnce(new WidgetUnavailableError(404))

      const wrapper = mountWidget({
        openImmediately: true,
        allowFileUpload: true,
      })
      await flushPromises()
      await nextTick()

      await openWidgetAndTypeMessage(wrapper, 'Test')
      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const attachBtn = wrapper.find('[data-testid="btn-attach"]')
      if (attachBtn.exists()) {
        expect((attachBtn.element as HTMLButtonElement).disabled).toBe(true)
      }
    })
  })

  describe('uploadWidgetFile rejects with WidgetUnavailableError', () => {
    it('shows unavailable message on file upload 404', async () => {
      mockUploadWidgetFile.mockRejectedValueOnce(new WidgetUnavailableError(404))

      const wrapper = mountWidget({
        openImmediately: true,
        allowFileUpload: true,
        testMode: true,
      })
      await flushPromises()
      await nextTick()

      const openBtn = wrapper.find('[data-testid="btn-open"]')
      if (openBtn.exists()) {
        await openBtn.trigger('click')
        await nextTick()
      }

      const fileInput = wrapper.find('[data-testid="input-file"]')
      const testFile = new File(['test content'], 'test.txt', { type: 'text/plain' })
      Object.defineProperty(fileInput.element, 'files', { value: [testFile] })
      await fileInput.trigger('change')
      await nextTick()

      const textarea = wrapper.find('[data-testid="input-message"]')
      await textarea.setValue('Check this file')

      await wrapper.find('[data-testid="btn-send"]').trigger('click')
      await flushPromises()
      await nextTick()

      const allText = wrapper.text()
      expect(allText).toContain('Chat is currently unavailable')
      expect((textarea.element as HTMLTextAreaElement).disabled).toBe(true)
    })
  })
})
