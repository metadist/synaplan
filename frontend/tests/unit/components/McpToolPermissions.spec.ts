import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import McpToolPermissions from '@/components/config/mcp/McpToolPermissions.vue'
import tools from '@/i18n/locales/en/tools.json'

const { toolPermissions, setToolPermissions, markToolsSeen, confirm, push, success, error } =
  vi.hoisted(() => ({
    toolPermissions: vi.fn(),
    setToolPermissions: vi.fn(),
    markToolsSeen: vi.fn(),
    confirm: vi.fn(),
    push: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
  }))

vi.mock('@/services/api/mcpServersApi', () => ({
  mcpServersApi: { toolPermissions, setToolPermissions, markToolsSeen },
}))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm }) }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ push, success, error }),
}))

const tool = (over: Record<string, unknown> = {}) => ({
  name: 'search',
  title: 'Search',
  description: 'Find pages',
  input_count: 1,
  input_names: ['query'],
  category: 'read',
  outcome: 'auto',
  default_outcome: 'auto',
  hinted: true,
  is_new: false,
  locked_reason: null,
  ...over,
})

const list = (tools: unknown[], canEdit = true) => ({
  success: true,
  error: null,
  can_edit: canEdit,
  tools,
})

const mountIt = async () => {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: tools } })
  const wrapper = mount(McpToolPermissions, {
    props: { serverId: 4, serverName: 'Notion' },
    global: { plugins: [i18n] },
  })
  await flushPromises()
  return wrapper
}

describe('McpToolPermissions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    markToolsSeen.mockResolvedValue(undefined)
  })

  it('shows a retry when the list cannot be loaded', async () => {
    toolPermissions.mockRejectedValue(new Error('down'))
    const wrapper = await mountIt()
    expect(wrapper.find('[data-testid="mcp-permissions-error"]').exists()).toBe(true)
    toolPermissions.mockResolvedValue(list([tool()]))
    await wrapper.get('[data-testid="btn-mcp-permissions-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="mcp-permissions-error"]').exists()).toBe(false)
  })

  it('says so when the connection has no tools', async () => {
    toolPermissions.mockResolvedValue(list([]))
    const wrapper = await mountIt()
    expect(wrapper.find('[data-testid="mcp-permissions-empty"]').exists()).toBe(true)
  })

  it('marks new tools as seen only for an editor', async () => {
    toolPermissions.mockResolvedValue(list([tool({ is_new: true })]))
    await mountIt()
    expect(markToolsSeen).toHaveBeenCalledWith(4)

    markToolsSeen.mockClear()
    toolPermissions.mockResolvedValue(list([tool({ is_new: true })], false))
    await mountIt()
    expect(markToolsSeen).not.toHaveBeenCalled()
  })

  it('asks before a destructive tool is set to auto and keeps it on no', async () => {
    const destructive = tool({
      name: 'delete-pages',
      title: 'Delete pages',
      category: 'destructive',
      outcome: 'approve',
      default_outcome: 'approve',
    })
    toolPermissions.mockResolvedValue(list([destructive]))
    confirm.mockResolvedValue(false)
    const wrapper = await mountIt()

    const selects = wrapper.findAll('select')
    await selects[selects.length - 1].setValue('auto')
    await flushPromises()

    expect(confirm).toHaveBeenCalled()
    expect(setToolPermissions).not.toHaveBeenCalled()
    expect(toolPermissions).toHaveBeenCalledTimes(1)
    expect((selects[selects.length - 1].element as HTMLSelectElement).value).toBe('approve')
  })

  it('saves a change at once and offers undo with the previous choice', async () => {
    toolPermissions.mockResolvedValue(list([tool()]))
    setToolPermissions.mockResolvedValue(list([tool({ outcome: 'block' })]))
    const wrapper = await mountIt()

    const selects = wrapper.findAll('select')
    await selects[selects.length - 1].setValue('block')
    await flushPromises()

    expect(setToolPermissions).toHaveBeenCalledWith(4, { tools: { search: 'block' } })
    const toast = push.mock.calls[0][0]
    expect(toast.action.label).toBeTruthy()
    setToolPermissions.mockResolvedValue(list([tool()]))
    toast.action.onClick()
    await flushPromises()
    expect(setToolPermissions).toHaveBeenLastCalledWith(4, { tools: { search: 'default' } })
    expect(success).toHaveBeenCalled()
  })

  it('undo restores an earlier override as that value, not the default', async () => {
    toolPermissions.mockResolvedValue(list([tool({ outcome: 'approve' })]))
    setToolPermissions.mockResolvedValue(list([tool({ outcome: 'block' })]))
    const wrapper = await mountIt()

    const selects = wrapper.findAll('select')
    await selects[selects.length - 1].setValue('block')
    await flushPromises()

    push.mock.calls[0][0].action.onClick()
    await flushPromises()
    expect(setToolPermissions).toHaveBeenLastCalledWith(4, { tools: { search: 'approve' } })
  })

  it('resets a changed tool to its default', async () => {
    toolPermissions.mockResolvedValue(list([tool({ outcome: 'block' })]))
    setToolPermissions.mockResolvedValue(list([tool()]))
    const wrapper = await mountIt()

    await wrapper.get('[data-testid="btn-mcp-tool-reset-search"]').trigger('click')
    await flushPromises()

    expect(setToolPermissions).toHaveBeenCalledWith(4, { tools: { search: 'default' } })
    expect(wrapper.find('[data-testid="btn-mcp-tool-reset-search"]').exists()).toBe(false)
  })

  it('keeps the list and the New marks when a save fails', async () => {
    toolPermissions.mockResolvedValueOnce(list([tool({ is_new: true })]))
    toolPermissions.mockResolvedValue(list([tool({ is_new: false })]))
    setToolPermissions.mockRejectedValue(new Error('down'))
    const wrapper = await mountIt()

    const selects = wrapper.findAll('select')
    await selects[selects.length - 1].setValue('block')
    await flushPromises()

    expect(error).toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="mcp-tool-new-search"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="mcp-permissions-loading"]').exists()).toBe(false)
  })

  it('does not send a category change when every tool of it is locked', async () => {
    toolPermissions.mockResolvedValue(
      list([tool({ locked_reason: 'group_policy', outcome: 'block' })])
    )
    const wrapper = await mountIt()
    const category = wrapper.get('[data-testid="select-mcp-category-read"]')
    expect((category.element as HTMLSelectElement).disabled).toBe(true)
  })
})
