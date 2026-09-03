import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick } from 'vue'

import FileOfficeActions from '@/components/files/FileOfficeActions.vue'

const { isDocumentToolsEnabled } = vi.hoisted(() => ({
  isDocumentToolsEnabled: vi.fn(() => false),
}))

vi.mock('@/composables/useOfficeConvertFeature', () => ({
  isOfficeConvertEnabled: () => true,
}))

vi.mock('@/composables/useDocumentToolsFeature', () => ({
  isDocumentToolsEnabled,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/services/filesService', () => ({
  downloadFile: vi.fn(),
  downloadGuestFile: vi.fn(),
  exportFile: vi.fn(),
  exportGuestFile: vi.fn(),
  combineFiles: vi.fn(),
  listFileRevisions: vi.fn().mockResolvedValue({ revisions: [] }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      common: { close: 'Close' },
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
        revisionsTitle: 'Version history',
        noRevisions: 'No versions',
        status_extracting: 'Loading',
      },
    },
  },
})

describe('FileOfficeActions', () => {
  let wrapper: VueWrapper | null = null

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    isDocumentToolsEnabled.mockReturnValue(false)
  })

  const mountActions = (filename: string): VueWrapper => {
    wrapper = mount(FileOfficeActions, {
      props: { fileId: 7, filename },
      attachTo: document.body,
      global: { plugins: [i18n], stubs: { Icon: { template: '<i />' } } },
    })
    return wrapper
  }

  const openMenu = async (filename = 'brief.docx'): Promise<VueWrapper> => {
    const mounted = mountActions(filename)
    await mounted.find('[data-testid="file-office-actions-trigger"]').trigger('click')
    await nextTick()
    await nextTick()
    return mounted
  }

  it('shows PDF export and preview for office files when the engine is on', async () => {
    await openMenu('brief.docx')
    expect(document.querySelector('[data-testid="file-office-actions-pdf"]')).not.toBeNull()
    expect(document.querySelector('[data-testid="file-office-actions-preview"]')).not.toBeNull()
  })

  it('hides PDF export for plain PDFs', async () => {
    await openMenu('report.pdf')
    expect(document.querySelector('[data-testid="file-office-actions-pdf"]')).toBeNull()
    expect(document.querySelector('[data-testid="file-office-actions-preview"]')).not.toBeNull()
  })

  it('renders the menu as a fixed overlay on the body so bubble overflow cannot clip it', async () => {
    const mounted = await openMenu()
    const menu = document.querySelector('[data-testid="file-office-actions-menu"]')
    expect(menu).toBeInstanceOf(HTMLElement)
    expect(menu?.classList.contains('fixed')).toBe(true)
    expect(menu?.classList.contains('overflow-y-auto')).toBe(true)
    expect(menu?.classList.contains('bottom-full')).toBe(false)
    expect(document.body.contains(menu)).toBe(true)
    expect(mounted.find('[data-testid="file-office-actions"]').element.contains(menu)).toBe(false)
  })

  it('closes on Escape', async () => {
    await openMenu()
    expect(document.querySelector('[data-testid="file-office-actions-menu"]')).not.toBeNull()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(document.querySelector('[data-testid="file-office-actions-menu"]')).toBeNull()
  })

  it('closes on click outside', async () => {
    await openMenu()
    expect(document.querySelector('[data-testid="file-office-actions-menu"]')).not.toBeNull()
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()
    expect(document.querySelector('[data-testid="file-office-actions-menu"]')).toBeNull()
  })

  it('teleports the revisions panel to the body', async () => {
    isDocumentToolsEnabled.mockReturnValue(true)
    await openMenu('brief.docx')
    const revisions = document.querySelector('[data-testid="file-office-actions-revisions"]')
    expect(revisions).not.toBeNull()
    await (revisions as HTMLElement).click()
    await nextTick()
    const panel = document.querySelector('[data-testid="file-revisions-panel"]')
    expect(panel).toBeInstanceOf(HTMLElement)
    expect(document.body.contains(panel)).toBe(true)
    expect(wrapper?.find('[data-testid="file-office-actions"]').element.contains(panel)).toBe(false)
  })
})
