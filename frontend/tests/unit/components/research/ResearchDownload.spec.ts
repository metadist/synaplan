import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import ResearchDownload from '@/components/research/ResearchDownload.vue'
import { useResearchStore } from '@/stores/research'
import { emptyProgress } from '@/services/chat/stream/handlers/research'

const { downloadReplyExport, canExportChats, notifyError } = vi.hoisted(() => ({
  downloadReplyExport: vi.fn(),
  canExportChats: vi.fn(() => true),
  notifyError: vi.fn(),
}))

vi.mock('@/services/api/replyExportApi', () => ({
  REPLY_EXPORT_FORMATS: ['docx', 'html', 'md'],
  downloadReplyExport,
}))
vi.mock('@/composables/useChatWelcome', () => ({ canExportChats }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: notifyError }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: { research: { download: { failed: 'The download did not work.' } } },
  },
})

function mountDownload(props: { runId?: string | null; guest?: boolean } = {}) {
  return mount(ResearchDownload, {
    props: { messageId: 42, runId: 'run-1', ...props },
    attachTo: document.body,
    global: { plugins: [i18n] },
  })
}

describe('ResearchDownload', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    downloadReplyExport.mockReset()
    canExportChats.mockReturnValue(true)
    notifyError.mockReset()
  })

  it('is hidden on a reply without a research run', () => {
    const wrapper = mountDownload({ runId: null })
    expect(wrapper.find('[data-testid="btn-research-download"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('is hidden for guests', () => {
    const wrapper = mountDownload({ guest: true })
    expect(wrapper.find('[data-testid="btn-research-download"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('is hidden when chat export is turned off', () => {
    canExportChats.mockReturnValue(false)
    const wrapper = mountDownload()
    expect(wrapper.find('[data-testid="btn-research-download"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('is hidden while the research still runs', () => {
    useResearchStore().progress = emptyProgress('run-1', 7, 'Which pump?')
    const wrapper = mountDownload()
    expect(wrapper.find('[data-testid="btn-research-download"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each(['docx', 'html', 'md'] as const)('asks for %s and closes the menu', async (format) => {
    downloadReplyExport.mockResolvedValue(undefined)
    const wrapper = mountDownload()

    await wrapper.get('[data-testid="btn-research-download"]').trigger('click')
    await wrapper.get(`[data-testid="btn-research-download-${format}"]`).trigger('click')
    await flushPromises()

    expect(downloadReplyExport).toHaveBeenCalledWith(42, format)
    expect(wrapper.find('[data-testid="dropdown-research-download"]').exists()).toBe(false)
    expect(notifyError).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('disables the control while a download runs and reports a failure in one sentence', async () => {
    let fail: (reason: Error) => void = () => {}
    downloadReplyExport.mockReturnValue(
      new Promise<void>((_, reject) => {
        fail = reject
      })
    )
    const wrapper = mountDownload()

    await wrapper.get('[data-testid="btn-research-download"]').trigger('click')
    await wrapper.get('[data-testid="btn-research-download-docx"]').trigger('click')
    expect(
      wrapper.get('[data-testid="btn-research-download"]').attributes('disabled')
    ).toBeDefined()
    expect(wrapper.find('[data-testid="btn-research-download"] svg.animate-spin').exists()).toBe(
      true
    )

    fail(new Error('network'))
    await flushPromises()

    expect(notifyError).toHaveBeenCalledWith('The download did not work.')
    expect(
      wrapper.get('[data-testid="btn-research-download"]').attributes('disabled')
    ).toBeUndefined()
    wrapper.unmount()
  })

  it('closes on Escape and on a press outside the menu', async () => {
    const wrapper = mountDownload()
    await wrapper.get('[data-testid="btn-research-download"]').trigger('click')
    expect(wrapper.find('[data-testid="dropdown-research-download"]').exists()).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="dropdown-research-download"]').exists()).toBe(false)
    expect(document.activeElement).toBe(
      wrapper.get('[data-testid="btn-research-download"]').element
    )

    await wrapper.get('[data-testid="btn-research-download"]').trigger('click')
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="dropdown-research-download"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
