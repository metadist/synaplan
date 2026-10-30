import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FilePickerBrowser from '@/components/files/FilePickerBrowser.vue'
import filesService from '@/services/filesService'

vi.mock('@/services/filesService', () => ({
  default: {
    getFacets: vi.fn(),
    listFiles: vi.fn(),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost',
}))

vi.mock('@/services/api/mediaAuth', () => ({
  useMediaSrc: () => ({ mediaSrc: (url: string) => url }),
}))

const listFiles = vi.mocked(filesService.listFiles)
const getFacets = vi.mocked(filesService.getFacets)

describe('FilePickerBrowser', () => {
  beforeEach(() => {
    listFiles.mockReset()
    getFacets.mockReset()
    getFacets.mockResolvedValue({
      source: {},
      vector_state: {},
      incoming: 0,
      total: 0,
    })
    listFiles.mockResolvedValue({
      files: [
        {
          id: 4,
          filename: 'notes.pdf',
          file_type: 'pdf',
          file_size: 20,
          status: 'uploaded',
          source: 'web_upload',
        },
      ],
      pagination: { page: 1, limit: 30, total: 1, pages: 1 },
    } as never)
  })

  it('opens on my files and asks the server for AI-created files on the other tab', async () => {
    const wrapper = mount(FilePickerBrowser, {
      props: { selectedIds: [] },
      global: { mocks: { $t: (key: string) => key } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="tab-file-selection-mine"]').attributes('aria-selected')).toBe(
      'true'
    )
    expect(listFiles).toHaveBeenCalledWith(
      expect.objectContaining({ excludeSource: 'generated,compute' })
    )

    listFiles.mockResolvedValue({
      files: [],
      pagination: { page: 1, limit: 30, total: 0, pages: 0 },
    } as never)
    await wrapper.get('[data-testid="tab-file-selection-ai"]').trigger('click')
    await flushPromises()
    expect(listFiles).toHaveBeenCalledWith(expect.objectContaining({ source: 'generated,compute' }))
    expect(wrapper.find('[data-testid="btn-file-selection-empty-mine"]').exists()).toBe(true)
  })
})
