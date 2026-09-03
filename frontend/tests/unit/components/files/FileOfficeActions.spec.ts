import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick } from 'vue'

import FileOfficeActions from '@/components/files/FileOfficeActions.vue'

vi.mock('@/composables/useOfficeConvertFeature', () => ({
  isOfficeConvertEnabled: () => true,
}))

vi.mock('@/composables/useDocumentToolsFeature', () => ({
  isDocumentToolsEnabled: () => false,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      files: {
        download: 'Download',
        downloadPdf: 'Download as PDF',
        preview: { open: 'Preview' },
        downloadFailed: 'fail',
        exportFailed: 'export fail',
        combinePdf: 'Combine as PDF',
        combined: 'Combined',
        combineFailed: 'combine fail',
        combineEngineRequired: 'engine',
        combineTooMany: 'too many {count}',
        combineOffice: 'Combine as {format}',
        revisions: 'Version history',
      },
    },
  },
})

const mountActions = (filename: string): VueWrapper =>
  mount(FileOfficeActions, {
    props: { fileId: 7, filename },
    attachTo: document.body,
    global: { plugins: [i18n], stubs: { Icon: { template: '<i />' } } },
  })

describe('FileOfficeActions', () => {
  afterEach(() => {
    document.body.replaceChildren()
  })

  it('shows PDF export and preview for office files when the engine is on', async () => {
    const wrapper = mountActions('brief.docx')
    await wrapper.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    await nextTick()
    expect(document.querySelector('[data-testid="file-office-actions-pdf"]')).not.toBeNull()
    expect(document.querySelector('[data-testid="file-office-actions-preview"]')).not.toBeNull()
    wrapper.unmount()
  })

  it('hides PDF export for plain PDFs', async () => {
    const wrapper = mountActions('report.pdf')
    await wrapper.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    await nextTick()
    expect(document.querySelector('[data-testid="file-office-actions-pdf"]')).toBeNull()
    expect(document.querySelector('[data-testid="file-office-actions-preview"]')).not.toBeNull()
    wrapper.unmount()
  })

  it('renders the menu as a fixed overlay on the body so bubble overflow cannot clip it', async () => {
    const wrapper = mountActions('brief.docx')
    await wrapper.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    await nextTick()

    const menu = document.querySelector('[data-testid="file-office-actions-menu"]')
    expect(menu).toBeInstanceOf(HTMLElement)
    expect(menu?.classList.contains('fixed')).toBe(true)
    expect(menu?.classList.contains('bottom-full')).toBe(false)
    expect(document.body.contains(menu)).toBe(true)
    expect(wrapper.find('[data-testid="file-office-actions"]').element.contains(menu)).toBe(false)
    wrapper.unmount()
  })
})
