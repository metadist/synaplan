import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FilesGrid from '@/components/files/FilesGrid.vue'
import filesService from '@/services/filesService'

vi.mock('@/services/filesService', () => ({
  default: {
    listFiles: vi.fn().mockResolvedValue({
      files: [],
      pagination: { page: 1, limit: 30, total: 0 },
    }),
    getFileGroups: vi.fn().mockResolvedValue([]),
    downloadFile: vi.fn(),
    deleteFile: vi.fn(),
    indexPromptFile: vi.fn(),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://api.test',
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({ setActiveChat: vi.fn() }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      files: {
        page: 'Page',
        previous: 'Previous',
        next: 'Next',
        empty: { generatedBody: 'Nothing generated yet.' },
        generated: {
          title: 'Generated',
          subtitle: 'Artefacts created in your chats and tasks.',
          download: 'Download',
          kinds: {
            all: 'All',
            image: 'Images',
            video: 'Videos',
            audio: 'Audio',
            document: 'Documents',
            calendar: 'Calendar',
          },
        },
      },
    },
  },
})

const mountGrid = () =>
  mount(FilesGrid, {
    global: {
      plugins: [i18n],
      stubs: {
        Icon: { template: '<i />' },
        MessageVideo: { template: '<div />' },
        MessageAudio: { template: '<div />' },
        FileVectorPill: { template: '<div />' },
      },
    },
  })

const listFiles = vi.mocked(filesService.listFiles)

describe('FilesGrid kind filter', () => {
  beforeEach(() => {
    listFiles.mockClear()
  })

  it('loads all generated files without a kind filter by default', async () => {
    mountGrid()
    await flushPromises()

    expect(listFiles).toHaveBeenCalledTimes(1)
    expect(listFiles).toHaveBeenCalledWith(
      expect.objectContaining({ source: 'generated', originKind: undefined, page: 1 })
    )
  })

  it('reloads from page 1 with origin_kind when a kind chip is selected', async () => {
    const wrapper = mountGrid()
    await flushPromises()
    listFiles.mockClear()

    await wrapper.find('[data-testid="btn-kind-document"]').trigger('click')
    await flushPromises()

    expect(listFiles).toHaveBeenCalledTimes(1)
    expect(listFiles).toHaveBeenCalledWith(
      expect.objectContaining({ source: 'generated', originKind: 'document', page: 1 })
    )
  })

  it('does not reload when the active chip is clicked again', async () => {
    const wrapper = mountGrid()
    await flushPromises()
    listFiles.mockClear()

    await wrapper.find('[data-testid="btn-kind-all"]').trigger('click')
    await flushPromises()

    expect(listFiles).not.toHaveBeenCalled()
  })
})
