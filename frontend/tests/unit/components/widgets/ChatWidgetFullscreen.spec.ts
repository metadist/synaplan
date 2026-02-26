import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import ChatWidget from '@/components/widgets/ChatWidget.vue'

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

describe('ChatWidget — Fullscreen Feature', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // --- Backward Compatibility ---

  describe('backward compatibility (no new props)', () => {
    it('renders without fullscreen props and shows standard layout', () => {
      const wrapper = mountWidget()
      const root = wrapper.find('[data-testid="comp-chat-widget"]')
      expect(root.exists()).toBe(true)
    })

    it('does not render fullscreen backdrop by default', () => {
      const wrapper = mountWidget()
      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(false)
    })

    it('does not show fullscreen toggle button by default', async () => {
      const wrapper = mountWidget()

      const openBtn = wrapper.find('[data-testid="btn-open"]')
      if (openBtn.exists()) {
        await openBtn.trigger('click')
        await nextTick()
      }

      expect(wrapper.find('[data-testid="btn-fullscreen"]').exists()).toBe(false)
    })

    it('shows the floating button when hideButton is not set', () => {
      const wrapper = mountWidget()
      expect(wrapper.find('[data-testid="btn-open"]').exists()).toBe(true)
    })

    it('applies standard max-width in non-fullscreen mode', async () => {
      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      const chatWindow = wrapper.find('[data-testid="section-chat-window"]')
      if (chatWindow.exists()) {
        expect(chatWindow.classes()).toContain('rounded-2xl')
      }
    })
  })

  // --- hideButton ---

  describe('hideButton prop', () => {
    it('hides the floating button when hideButton is true', () => {
      const wrapper = mountWidget({ hideButton: true })
      expect(wrapper.find('[data-testid="btn-open"]').exists()).toBe(false)
    })

    it('shows the floating button when hideButton is false', () => {
      const wrapper = mountWidget({ hideButton: false })
      expect(wrapper.find('[data-testid="btn-open"]').exists()).toBe(true)
    })
  })

  // --- fullscreenMode ---

  describe('fullscreenMode prop', () => {
    it('shows backdrop when fullscreenMode=true and chat is open', async () => {
      const wrapper = mountWidget({
        fullscreenMode: true,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      const backdrop = wrapper.find('[data-testid="fullscreen-backdrop"]')
      expect(backdrop.exists()).toBe(true)
    })

    it('does not show backdrop when fullscreenMode=true but chat is closed', () => {
      const wrapper = mountWidget({ fullscreenMode: true })

      const backdrop = wrapper.find('[data-testid="fullscreen-backdrop"]')
      expect(backdrop.exists()).toBe(false)
    })

    it('does not show backdrop when fullscreenMode=false and chat is open', async () => {
      const wrapper = mountWidget({
        fullscreenMode: false,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(false)
    })

    it('applies fullscreen classes to root when fullscreen and open', async () => {
      const wrapper = mountWidget({
        fullscreenMode: true,
        openImmediately: true,
        testMode: false,
      })
      await flushPromises()
      await nextTick()

      const root = wrapper.find('[data-testid="comp-chat-widget"]')
      expect(root.classes()).toContain('inset-0')
      expect(root.classes()).toContain('flex')
      expect(root.classes()).toContain('items-center')
      expect(root.classes()).toContain('justify-center')
    })
  })

  // --- allowFullscreen ---

  describe('allowFullscreen prop', () => {
    it('shows fullscreen toggle when allowFullscreen=true and chat is open', async () => {
      const wrapper = mountWidget({
        allowFullscreen: true,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      const btn = wrapper.find('[data-testid="btn-fullscreen"]')
      expect(btn.exists()).toBe(true)
    })

    it('does not show fullscreen toggle when allowFullscreen=false', async () => {
      const wrapper = mountWidget({
        allowFullscreen: false,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      expect(wrapper.find('[data-testid="btn-fullscreen"]').exists()).toBe(false)
    })

    it('toggles fullscreen state when fullscreen button is clicked', async () => {
      const wrapper = mountWidget({
        allowFullscreen: true,
        fullscreenMode: false,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(false)

      const btn = wrapper.find('[data-testid="btn-fullscreen"]')
      await btn.trigger('click')
      await nextTick()

      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(true)

      await btn.trigger('click')
      await nextTick()

      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(false)
    })
  })

  // --- Close behavior ---

  describe('close behavior', () => {
    it('close button hides the chat window', async () => {
      const wrapper = mountWidget({ openImmediately: true })
      await flushPromises()
      await nextTick()

      expect(wrapper.find('[data-testid="section-chat-window"]').exists()).toBe(true)

      const closeBtn = wrapper.find('[data-testid="btn-close"]')
      await closeBtn.trigger('click')
      await nextTick()

      expect(wrapper.find('[data-testid="section-chat-window"]').exists()).toBe(false)
    })

    it('backdrop click closes the chat in fullscreen mode', async () => {
      const wrapper = mountWidget({
        fullscreenMode: true,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      const backdrop = wrapper.find('[data-testid="fullscreen-backdrop"]')
      expect(backdrop.exists()).toBe(true)

      await backdrop.trigger('click')
      await nextTick()

      expect(wrapper.find('[data-testid="section-chat-window"]').exists()).toBe(false)
    })
  })

  // --- Combined scenarios ---

  describe('combined: fullscreenMode + hideButton + allowFullscreen', () => {
    it('works with all three options enabled (CastApp config)', async () => {
      const wrapper = mountWidget({
        hideButton: true,
        fullscreenMode: true,
        allowFullscreen: true,
        openImmediately: true,
      })
      await flushPromises()
      await nextTick()

      expect(wrapper.find('[data-testid="btn-open"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="btn-fullscreen"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="section-chat-window"]').exists()).toBe(true)
    })

    it('works with none of the options set (standard widget)', () => {
      const wrapper = mountWidget()

      expect(wrapper.find('[data-testid="btn-open"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="fullscreen-backdrop"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="btn-fullscreen"]').exists()).toBe(false)
    })
  })
})
