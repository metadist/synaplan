import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useFilePickerList } from '@/composables/useFilePickerList'
import filesService from '@/services/filesService'

vi.mock('@/services/filesService', () => ({
  default: {
    listFiles: vi.fn(),
  },
}))

const listFiles = vi.mocked(filesService.listFiles)

function page(total = 1) {
  return {
    files: [
      {
        id: 1,
        filename: 'notes.pdf',
        file_type: 'pdf',
        file_size: 10,
        status: 'uploaded',
      },
    ],
    pagination: { page: 1, limit: 30, total, pages: 1 },
  }
}

describe('useFilePickerList', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    listFiles.mockReset()
    listFiles.mockResolvedValue(page() as never)
  })

  it('loads my files and switches the server filter for AI-created', async () => {
    const picker = useFilePickerList()
    await picker.reload()
    expect(listFiles).toHaveBeenLastCalledWith(
      expect.objectContaining({ excludeSource: 'generated,compute', page: 1 })
    )

    picker.scope.value = 'ai'
    await flushPromises()
    expect(listFiles).toHaveBeenLastCalledWith(
      expect.objectContaining({ source: 'generated,compute' })
    )
  })

  it('debounces search and pages with load more', async () => {
    listFiles.mockResolvedValue(page(60) as never)
    const picker = useFilePickerList()
    picker.search.value = 'notes'
    await vi.advanceTimersByTimeAsync(299)
    expect(listFiles).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(listFiles).toHaveBeenCalledWith(expect.objectContaining({ search: 'notes', page: 1 }))

    picker.loadMore()
    await flushPromises()
    expect(listFiles).toHaveBeenCalledWith(expect.objectContaining({ search: 'notes', page: 2 }))
  })
})
