import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UrlWatchPanel from '@/components/config/UrlWatchPanel.vue'
import type { UrlWatch } from '@/services/api/urlWatchesApi'

const { mockList, mockCreate, mockRemove, mockConfirm } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockCreate: vi.fn(),
  mockRemove: vi.fn(),
  mockConfirm: vi.fn(),
}))

vi.mock('@/services/api/urlWatchesApi', () => ({
  urlWatchesApi: {
    list: mockList,
    create: mockCreate,
    remove: mockRemove,
    get: vi.fn(),
    refresh: vi.fn(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: mockConfirm }),
}))

const watch: UrlWatch = {
  id: 3,
  url: 'https://example.com/news',
  title: 'News',
  preview: 'Today',
  fetchedAt: '2026-09-08T09:00:00+00:00',
  created: '2026-09-08T08:00:00+00:00',
  updated: '2026-09-08T09:00:00+00:00',
  body: '',
}

const mountPanel = async () => {
  const wrapper = mount(UrlWatchPanel)
  await flushPromises()
  return wrapper
}

describe('UrlWatchPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfirm.mockResolvedValue(true)
  })

  it('shows the empty state when nothing is watched', async () => {
    mockList.mockResolvedValue([])
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="url-watch-empty"]').text()).toContain('No pages watched')
  })

  it('lists watched pages', async () => {
    mockList.mockResolvedValue([watch])
    const wrapper = await mountPanel()
    expect(wrapper.find('[data-testid="url-watch-empty"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="url-watch-list"]').text()).toContain(
      'https://example.com/news'
    )
  })

  it('creates a watch from the form', async () => {
    mockList.mockResolvedValue([])
    mockCreate.mockResolvedValue(watch)
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-input"]').setValue('https://example.com/news')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mockCreate).toHaveBeenCalledWith('https://example.com/news')
    expect(wrapper.get('[data-testid="url-watch-list"]').text()).toContain('News')
  })

  it('deletes a watch after confirm', async () => {
    mockList.mockResolvedValue([watch])
    mockRemove.mockResolvedValue(undefined)
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-delete"]').trigger('click')
    await flushPromises()
    expect(mockRemove).toHaveBeenCalledWith(3)
    expect(wrapper.find('[data-testid="url-watch-empty"]').exists()).toBe(true)
  })
})
