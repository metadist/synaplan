import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UrlWatchPanel from '@/components/config/UrlWatchPanel.vue'
import { ApiError } from '@/services/api/httpClient'
import type { UrlWatch } from '@/services/api/urlWatchesApi'

const {
  mockList,
  mockCreate,
  mockRemove,
  mockConfirm,
  mockRefresh,
  mockGet,
  mockSuccess,
  mockError,
} = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockCreate: vi.fn(),
  mockRemove: vi.fn(),
  mockConfirm: vi.fn(),
  mockRefresh: vi.fn(),
  mockGet: vi.fn(),
  mockSuccess: vi.fn(),
  mockError: vi.fn(),
}))

vi.mock('@/services/api/urlWatchesApi', () => ({
  urlWatchesApi: {
    list: mockList,
    create: mockCreate,
    remove: mockRemove,
    get: mockGet,
    refresh: mockRefresh,
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
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
  lastDiffText: null,
  lastError: null,
  lastFailedAt: null,
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
    mockCreate.mockResolvedValue({ watch, created: true })
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

  it('disables every Check now while one refresh is in flight', async () => {
    const other: UrlWatch = {
      ...watch,
      id: 4,
      url: 'https://example.com/other',
      title: 'Other',
    }
    mockList.mockResolvedValue([watch, other])
    let finishRefresh: (value: {
      watch: UrlWatch
      compare: { status: string; diffText: string }
    }) => void = () => undefined
    mockRefresh.mockReturnValue(
      new Promise((resolve) => {
        finishRefresh = resolve
      })
    )

    const wrapper = await mountPanel()
    const buttons = wrapper.findAll('[data-testid="url-watch-check"]')
    expect(buttons).toHaveLength(2)

    await buttons[0].trigger('click')
    await flushPromises()

    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[1].attributes('disabled')).toBeDefined()
    expect(mockRefresh).toHaveBeenCalledTimes(1)

    await buttons[1].trigger('click')
    await flushPromises()
    expect(mockRefresh).toHaveBeenCalledTimes(1)

    finishRefresh({ watch, compare: { status: 'unchanged', diffText: '' } })
    await flushPromises()
    expect(buttons[0].attributes('disabled')).toBeUndefined()
    expect(buttons[1].attributes('disabled')).toBeUndefined()
  })

  it('toasts the blocked-URL copy when create returns blocked_url', async () => {
    mockList.mockResolvedValue([])
    mockCreate.mockRejectedValue(
      new ApiError(400, 'URL points to a private/blocked address', 'blocked_url')
    )
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-input"]').setValue('http://127.0.0.1/page.html')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mockError).toHaveBeenCalledWith('That address cannot be watched (private or blocked).')
  })

  it('reports an existing watch instead of a first save', async () => {
    mockList.mockResolvedValue([watch])
    mockCreate.mockResolvedValue({ watch, created: false })
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-input"]').setValue('https://example.com/news')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mockSuccess).toHaveBeenCalledWith('This page is already watched.')
  })

  it('shows the API reason when a check fails', async () => {
    mockList.mockResolvedValue([watch])
    mockRefresh.mockRejectedValue(
      new ApiError(400, 'could not read the page: HTTP 404', 'HTTP_400')
    )
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-check"]').trigger('click')
    await flushPromises()
    expect(mockError).toHaveBeenCalledWith('could not read the page: HTTP 404')
    expect(wrapper.get('[data-testid="url-watch-status"]').text()).toContain('Last saved')
  })

  it('renders the last diff in the saved-copy dialog', async () => {
    const changed: UrlWatch = {
      ...watch,
      lastDiffText: '- old uuid\n+ new uuid',
      body: '{ "uuid": "new" }',
    }
    mockList.mockResolvedValue([changed])
    mockGet.mockResolvedValue(changed)
    const wrapper = await mountPanel()
    await wrapper.get('[data-testid="url-watch-view"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="url-watch-diff"]').text()).toContain('- old uuid')
    expect(wrapper.get('[data-testid="url-watch-body"]').text()).toContain('new')
  })

  it('labels a failed check instead of Not fetched yet', async () => {
    const failed: UrlWatch = {
      ...watch,
      fetchedAt: null,
      lastError: 'URL points to a private/blocked address',
      lastFailedAt: '2026-09-10T17:00:00+00:00',
    }
    mockList.mockResolvedValue([failed])
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="url-watch-status"]').text()).toContain(
      'Last check failed: URL points to a private/blocked address'
    )
  })
})
