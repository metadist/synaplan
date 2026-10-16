import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LongTermMemoryTab from '@/components/memories/LongTermMemoryTab.vue'
import {
  deleteAllMessageDigestEntries,
  deleteMessageDigestEntry,
  listMessageDigestEntries,
} from '@/services/api/messageDigestEntriesApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useRouter } from 'vue-router'

vi.mock('@/services/api/messageDigestEntriesApi', () => ({
  listMessageDigestEntries: vi.fn(),
  deleteMessageDigestEntry: vi.fn(),
  deleteAllMessageDigestEntries: vi.fn(),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: vi.fn(),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: vi.fn(),
}))

const listMock = vi.mocked(listMessageDigestEntries)
const deleteOneMock = vi.mocked(deleteMessageDigestEntry)
const deleteAllMock = vi.mocked(deleteAllMessageDigestEntries)
const confirmMock = vi.fn()
const successMock = vi.fn()
const errorMock = vi.fn()
const pushMock = vi.fn()

const entry = {
  id: 7,
  title: 'office rent letter about the payment increase',
  messageId: 1234,
  chatId: 42 as number | null,
  chatTitle: 'Rent' as string | null,
  channel: 'web',
  sourceDate: 1747216800,
  created: 1747216900,
}

function pageOf(
  entries: (typeof entry)[],
  extras: { enabled?: boolean; memoriesEnabled?: boolean; total?: number } = {}
) {
  return {
    enabled: extras.enabled ?? true,
    memoriesEnabled: extras.memoriesEnabled ?? true,
    entries,
    total: extras.total ?? entries.length,
    page: 1,
    limit: 25,
  }
}

async function mountTab() {
  const wrapper = mount(LongTermMemoryTab)
  await flushPromises()
  return wrapper
}

describe('LongTermMemoryTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    confirmMock.mockResolvedValue(true)
    vi.mocked(useDialog).mockReturnValue({
      confirm: confirmMock,
    } as unknown as ReturnType<typeof useDialog>)
    vi.mocked(useNotification).mockReturnValue({
      success: successMock,
      error: errorMock,
      info: vi.fn(),
      warning: vi.fn(),
    } as unknown as ReturnType<typeof useNotification>)
    vi.mocked(useRouter).mockReturnValue({ push: pushMock } as unknown as ReturnType<
      typeof useRouter
    >)
  })

  it('renders an entry with its title and chat', async () => {
    listMock.mockResolvedValue(pageOf([entry]))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain(entry.title)
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain('Rent')
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain('Web')
    expect(wrapper.get('[data-testid="text-long-term-total"]').text()).toContain('1')
    expect(wrapper.emitted('availability')?.at(-1)).toEqual([true])
  })

  it('names the channel from the stored message type code', async () => {
    listMock.mockResolvedValue(
      pageOf([
        { ...entry, id: 1, channel: 'mail' },
        { ...entry, id: 2, channel: 'wtsp' },
        { ...entry, id: 3, channel: 'wdgt' },
        { ...entry, id: 4, channel: 'tgrm' },
      ])
    )

    const wrapper = await mountTab()

    const rows = wrapper.findAll('[data-testid="item-long-term-entry"]').map((row) => row.text())
    expect(rows[0]).toContain('Email')
    expect(rows[1]).toContain('WhatsApp')
    expect(rows[2]).toContain('Chat widget')
    expect(rows[3]).toContain('Telegram')
  })

  it('links Settings inside the intro sentence', async () => {
    listMock.mockResolvedValue(pageOf([entry]))

    const wrapper = await mountTab()

    const intro = wrapper.get('[data-testid="text-long-term-intro"]')
    expect(intro.text()).toContain('turn memories off in Settings.')
    expect(intro.text().match(/Settings/g)).toHaveLength(1)
    await intro.get('[data-testid="btn-long-term-settings"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings', hash: '#memories' })
  })

  it('says the AI is not using kept entries while memories are off', async () => {
    listMock.mockResolvedValue(pageOf([entry], { memoriesEnabled: false }))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="text-long-term-memories-off"]').text()).toContain(
      'They stay until you delete them.'
    )
    expect(wrapper.find('[data-testid="text-long-term-server-off"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain(entry.title)
  })

  it('shows the memories-off empty state', async () => {
    listMock.mockResolvedValue(pageOf([], { memoriesEnabled: false }))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="state-long-term-empty"]').text()).toContain(
      'Memories are off'
    )
    await wrapper.get('[data-testid="btn-long-term-empty-action"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings', hash: '#memories' })
  })

  it('shows the no-entries empty state', async () => {
    listMock.mockResolvedValue(pageOf([]))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="state-long-term-empty"]').text()).toContain(
      'when you write something important'
    )
    await wrapper.get('[data-testid="btn-long-term-empty-action"]').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'chat' })
  })

  it('hides the tab when the server switch is off and there are no entries', async () => {
    listMock.mockResolvedValue(pageOf([], { enabled: false }))

    const wrapper = await mountTab()

    expect(wrapper.find('[data-testid="section-long-term-memory"]').exists()).toBe(false)
    expect(wrapper.emitted('availability')?.at(-1)).toEqual([false])
  })

  it('keeps existing entries visible when the server switch is off', async () => {
    listMock.mockResolvedValue(pageOf([entry], { enabled: false, total: 1 }))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="text-long-term-server-off"]').text()).toContain(
      'off on this server'
    )
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain(entry.title)
    expect(wrapper.emitted('availability')?.at(-1)).toEqual([true])
  })

  it('deletes one entry after confirmation', async () => {
    listMock.mockResolvedValue(pageOf([entry]))
    deleteOneMock.mockResolvedValue({ success: true })

    const wrapper = await mountTab()
    await wrapper.get('[data-testid="btn-long-term-delete"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledWith(
      expect.objectContaining({
        danger: true,
        message: 'The AI will no longer use this entry. Your chat message stays.',
      })
    )
    expect(deleteOneMock).toHaveBeenCalledWith(7)
    expect(wrapper.find('[data-testid="item-long-term-entry"]').exists()).toBe(false)
    expect(successMock).toHaveBeenCalledWith('Deleted. The AI will no longer use this entry.')
  })

  it('deletes every entry and reports how many', async () => {
    listMock.mockResolvedValue(pageOf([entry], { total: 2 }))
    deleteAllMock.mockResolvedValue({ success: true, deleted: 2 })

    const wrapper = await mountTab()
    await wrapper.get('[data-testid="btn-long-term-delete-all"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledWith(
      expect.objectContaining({
        danger: true,
        message:
          'Delete 2 long-term memory entries? The AI will no longer use them. Your chat messages stay.',
      })
    )
    expect(wrapper.find('[data-testid="item-long-term-entry"]').exists()).toBe(false)
    expect(successMock).toHaveBeenCalledWith(
      'Deleted 2 long-term memory entries. Your chat messages stay.'
    )
  })

  it('reloads what is left when delete all fails part way', async () => {
    const second = { ...entry, id: 8, title: 'budget note' }
    listMock.mockResolvedValueOnce(pageOf([entry, second])).mockResolvedValueOnce(pageOf([second]))
    deleteAllMock.mockRejectedValue(new Error('HTTP 500: Internal Server Error'))

    const wrapper = await mountTab()
    await wrapper.get('[data-testid="btn-long-term-delete-all"]').trigger('click')
    await flushPromises()

    expect(errorMock).toHaveBeenCalledWith(
      'Not all entries were deleted. The list shows what is left. Try again.'
    )
    expect(listMock).toHaveBeenCalledTimes(2)
    const rows = wrapper.findAll('[data-testid="item-long-term-entry"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('budget note')
  })

  it('does not skip an entry when loading more after a delete', async () => {
    const firstPage = Array.from({ length: 25 }, (_, i) => ({ ...entry, id: 100 + i }))
    const shifted = { ...entry, id: 200, title: 'shifted into page one' }
    const later = { ...entry, id: 201, title: 'second page entry' }
    listMock
      .mockResolvedValueOnce(pageOf(firstPage, { total: 27 }))
      .mockResolvedValueOnce(pageOf([...firstPage.slice(1), shifted], { total: 26 }))
      .mockResolvedValueOnce(pageOf([later], { total: 26 }))
    deleteOneMock.mockResolvedValue({ success: true })

    const wrapper = await mountTab()
    await wrapper.findAll('[data-testid="btn-long-term-delete"]')[0].trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="btn-long-term-load-more"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="btn-long-term-load-more"]').trigger('click')
    await flushPromises()

    expect(listMock).toHaveBeenNthCalledWith(2, 1, 25)
    expect(listMock).toHaveBeenNthCalledWith(3, 2, 25)
    const titles = wrapper.findAll('[data-testid="item-long-term-entry"] h3').map((h) => h.text())
    expect(titles).toHaveLength(26)
    expect(titles).toContain('shifted into page one')
    expect(titles).toContain('second page entry')
    expect(wrapper.find('[data-testid="btn-long-term-load-more"]').exists()).toBe(false)
  })

  it('keeps the list and reports when a reload fails', async () => {
    listMock
      .mockResolvedValueOnce(pageOf([entry], { total: 2 }))
      .mockRejectedValueOnce(new Error('network'))
    deleteAllMock.mockRejectedValue(new Error('HTTP 500: Internal Server Error'))

    const wrapper = await mountTab()
    await wrapper.get('[data-testid="btn-long-term-delete-all"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="state-long-term-loading"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain(entry.title)
    expect(errorMock).toHaveBeenCalledWith('Long-term memory could not be loaded. Try again.')
  })

  it('uses the singular for one entry', async () => {
    listMock.mockResolvedValue(pageOf([entry]))

    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="text-long-term-total"]').text()).toBe(
      '1 long-term memory entry'
    )
  })

  it('shows honest copy when a delete fails and keeps the row', async () => {
    listMock.mockResolvedValue(pageOf([entry]))
    deleteOneMock.mockRejectedValue(new Error('HTTP 500: Internal Server Error'))

    const wrapper = await mountTab()
    await wrapper.get('[data-testid="btn-long-term-delete"]').trigger('click')
    await flushPromises()

    expect(errorMock).toHaveBeenCalledWith('This entry was not deleted. Try again.')
    expect(String(errorMock.mock.calls[0][0])).not.toMatch(/500|HTTP/)
    expect(wrapper.get('[data-testid="item-long-term-entry"]').text()).toContain(entry.title)
  })

  it('explains a missing chat instead of linking to it', async () => {
    listMock.mockResolvedValue(pageOf([{ ...entry, chatId: null, chatTitle: null }]))

    const wrapper = await mountTab()

    expect(wrapper.find('[data-testid="btn-long-term-open"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-long-term-open-unavailable"]').text()).toContain(
      'no longer available'
    )
    expect(wrapper.text()).toContain('Chat')
  })
})
