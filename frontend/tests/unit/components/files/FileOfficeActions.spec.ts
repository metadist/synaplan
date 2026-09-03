import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

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

describe('FileOfficeActions', () => {
  it('shows PDF export and preview for office files when the engine is on', async () => {
    const wrapper = mount(FileOfficeActions, {
      props: { fileId: 7, filename: 'brief.docx' },
      global: { plugins: [i18n], stubs: { Icon: { template: '<i />' } } },
    })
    await wrapper.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    expect(wrapper.find('[data-testid="file-office-actions-pdf"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="file-office-actions-preview"]').exists()).toBe(true)
  })

  it('hides PDF export for plain PDFs', async () => {
    const wrapper = mount(FileOfficeActions, {
      props: { fileId: 7, filename: 'report.pdf' },
      global: { plugins: [i18n], stubs: { Icon: { template: '<i />' } } },
    })
    await wrapper.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    expect(wrapper.find('[data-testid="file-office-actions-pdf"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="file-office-actions-preview"]').exists()).toBe(true)
  })
})
