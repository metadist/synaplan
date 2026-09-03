import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import DocumentPreviewModal from '@/components/files/DocumentPreviewModal.vue'

vi.mock('@/composables/useOfficeConvertFeature', () => ({
  isOfficeConvertEnabled: () => true,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn() }),
}))

vi.mock('@/services/api/mediaAuth', () => ({
  useMediaSrc: () => ({ mediaSrc: (url: string) => url }),
}))

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => false,
  }
})

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://api.test',
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      files: { download: 'Download', downloadPdf: 'Download as PDF' },
      common: { close: 'Close' },
    },
  },
})

describe('DocumentPreviewModal', () => {
  let wrapper: VueWrapper | null = null

  beforeEach(() => {
    const app = document.createElement('div')
    app.id = 'app'
    document.body.appendChild(app)
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.getElementById('app')?.remove()
  })

  const mountModal = (props: { open: boolean; file: { id: number; filename: string } }) => {
    wrapper = mount(DocumentPreviewModal, {
      props,
      global: { plugins: [i18n], stubs: { Icon: { template: '<i />' } } },
    })
    return wrapper
  }

  it('renders an iframe for an office file when open', () => {
    mountModal({ open: true, file: { id: 9, filename: 'brief.docx' } })
    const frame = document.querySelector('[data-testid="document-preview-frame"]')
    expect(frame).not.toBeNull()
    expect(frame?.getAttribute('src')).toContain('/api/v1/files/9/export')
    expect(frame?.getAttribute('src')).toContain('inline=1')
    expect(document.querySelector('[data-testid="document-preview-download-pdf"]')).not.toBeNull()
  })

  it('hides Download as PDF for an already-PDF file', () => {
    mountModal({ open: true, file: { id: 9, filename: 'report.pdf' } })
    expect(document.querySelector('[data-testid="document-preview-download-pdf"]')).toBeNull()
  })

  it('does not render when closed', () => {
    mountModal({ open: false, file: { id: 9, filename: 'brief.docx' } })
    expect(document.querySelector('[data-testid="document-preview-modal"]')).toBeNull()
  })
})
