import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import NotionWatches from '@/components/apps/panels/notion/NotionWatches.vue'
import NotionRecentChanges from '@/components/apps/panels/notion/NotionRecentChanges.vue'

const { watches, createWatch, setWatchEnabled, deleteWatch, activity, confirm, success, error } =
  vi.hoisted(() => ({
    watches: vi.fn(),
    createWatch: vi.fn(),
    setWatchEnabled: vi.fn(),
    deleteWatch: vi.fn(),
    activity: vi.fn(),
    confirm: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
  }))

vi.mock('@/services/api/notionAppApi', () => ({
  notionAppApi: { watches, createWatch, setWatchEnabled, deleteWatch, activity },
}))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))
vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm }),
}))

const row = {
  id: 7,
  page_url: 'https://www.notion.so/0123456789abcdef0123456789abcdef',
  title: 'Roadmap',
  action: 'searchable' as const,
  folder: 'Notion',
  task_id: null,
  task_name: null,
  file_id: 42,
  enabled: true,
  checked_at: Math.floor(Date.now() / 1000) - 60,
  changed_at: null,
  last_error: null,
}

const mountWatches = async () => {
  const wrapper = mount(NotionWatches, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('NotionWatches', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    watches.mockResolvedValue({ success: true, watches: [], tasks: [], limit: 20 })
  })

  it('shows one sentence and the form when nothing is followed', async () => {
    const wrapper = await mountWatches()
    expect(wrapper.get('[data-testid="text-notion-watches-empty"]').text()).toContain(
      'Paste a Notion page link'
    )
    expect(wrapper.find('[data-testid="form-notion-watch"]').exists()).toBe(true)
  })

  it('follows a page and says where its copy is', async () => {
    createWatch.mockResolvedValue(row)
    const wrapper = await mountWatches()

    await wrapper.get('[data-testid="input-notion-watch-page"]').setValue(row.page_url)
    await wrapper.get('[data-testid="form-notion-watch"]').trigger('submit')
    await flushPromises()

    expect(createWatch).toHaveBeenCalledWith({
      page: row.page_url,
      action: 'searchable',
      folder: 'Notion',
    })
    expect(success).toHaveBeenCalledWith(
      'The page is followed. Its copy is in the folder “Notion” in Files.'
    )
    expect(wrapper.findAll('[data-testid="item-notion-watch"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="link-notion-watch-file"]').exists()).toBe(true)
  })

  it('points to saved tasks when there is none to start', async () => {
    const wrapper = await mountWatches()
    await wrapper.get('[data-testid="select-notion-watch-action"]').setValue('task')
    expect(wrapper.find('[data-testid="text-notion-watch-no-tasks"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="btn-notion-watch-add"]').attributes('disabled')).toBeDefined()
  })

  it('shows why the last check failed in plain words', async () => {
    watches.mockResolvedValue({
      success: true,
      watches: [{ ...row, last_error: 'Notion is not connected any more.' }],
      tasks: [],
      limit: 20,
    })
    const wrapper = await mountWatches()
    expect(wrapper.get('[data-testid="text-notion-watch-error"]').text()).toBe(
      'Notion is not connected any more.'
    )
  })

  it('pauses and stops following from the same row', async () => {
    watches.mockResolvedValue({ success: true, watches: [row], tasks: [], limit: 20 })
    setWatchEnabled.mockResolvedValue({ ...row, enabled: false })
    confirm.mockResolvedValue(true)
    deleteWatch.mockResolvedValue(undefined)
    const wrapper = await mountWatches()

    await wrapper.get('[data-testid="btn-notion-watch-toggle"]').trigger('click')
    await flushPromises()
    expect(setWatchEnabled).toHaveBeenCalledWith(7, false)
    expect(wrapper.get('[data-testid="btn-notion-watch-toggle"]').text()).toBe('Resume')

    await wrapper.get('[data-testid="btn-notion-watch-remove"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ danger: true }))
    expect(deleteWatch).toHaveBeenCalledWith(7)
    expect(wrapper.findAll('[data-testid="item-notion-watch"]')).toHaveLength(0)
  })
})

describe('NotionRecentChanges', () => {
  it('lists what Synaplan changed and links to Notion', async () => {
    activity.mockResolvedValue([
      {
        id: 1,
        action: 'created',
        title: 'Weekly notes',
        url: 'https://www.notion.so/abc',
        created_at: Math.floor(Date.now() / 1000),
      },
    ])
    const wrapper = mount(NotionRecentChanges)
    await flushPromises()
    const item = wrapper.get('[data-testid="item-notion-activity"]')
    expect(item.text()).toContain('Weekly notes')
    expect(item.text()).toContain('Created')
    expect(item.get('a').attributes('href')).toBe('https://www.notion.so/abc')
  })

  it('says what will show up when the list is empty', async () => {
    activity.mockResolvedValue([])
    const wrapper = mount(NotionRecentChanges)
    await flushPromises()
    expect(wrapper.get('[data-testid="text-notion-activity-empty"]').text()).toContain(
      'Pages Synaplan creates or changes'
    )
  })
})
