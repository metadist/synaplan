import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import NotionAppPanel from '@/components/apps/panels/NotionAppPanel.vue'

const status = {
  success: true,
  status: 'not_connected' as 'not_connected' | 'connected' | 'reauth_required',
  server_id: null as number | null,
  allow_write: false,
  write_auto: false,
  sharing: { shared_by: null as string | null, can_share: false, shared_on: false },
}

const {
  statusMock,
  accountMock,
  setWriting,
  connect,
  disconnectOAuth,
  confirm,
  success,
  error,
  push,
  setShared,
  useShared,
} = vi.hoisted(() => ({
  statusMock: vi.fn(),
  accountMock: vi.fn(),
  setWriting: vi.fn(),
  connect: vi.fn(),
  disconnectOAuth: vi.fn(),
  confirm: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  push: vi.fn(),
  setShared: vi.fn(),
  useShared: vi.fn(),
}))

vi.mock('@/services/api/notionAppApi', () => ({
  notionAppApi: {
    status: statusMock,
    account: accountMock,
    setWriting,
    connect,
    setShared,
    useShared,
    activity: vi.fn(async () => []),
    watches: vi.fn(async () => ({ success: true, watches: [], tasks: [], limit: 20 })),
  },
}))
vi.mock('@/services/api/mcpServersApi', () => ({
  mcpServersApi: { disconnectOAuth },
}))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))
vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push, replace: vi.fn() }),
}))

const mountPanel = async () => {
  const wrapper = mount(NotionAppPanel)
  await flushPromises()
  return wrapper
}

describe('NotionAppPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    status.status = 'not_connected'
    status.server_id = null
    status.allow_write = false
    status.write_auto = false
    status.sharing = { shared_by: null, can_share: false, shared_on: false }
    statusMock.mockImplementation(async () => ({ ...status }))
    accountMock.mockResolvedValue({
      success: true,
      workspace_name: null,
      user_name: null,
      user_email: null,
    })
  })

  it('names the Notion account and workspace the AI uses', async () => {
    status.status = 'connected'
    status.server_id = 4
    accountMock.mockResolvedValue({
      success: true,
      workspace_name: 'Acme',
      user_name: 'Ada Lovelace',
      user_email: 'ada@example.com',
    })
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-testid="text-notion-account"]').text()).toBe(
      'Signed in to the Notion workspace Acme as Ada Lovelace (ada@example.com).'
    )
    expect(wrapper.text()).toContain('The AI sees only what this Notion account can see.')
  })

  it('still says Notion is connected when the account cannot be read', async () => {
    status.status = 'connected'
    status.server_id = 4
    accountMock.mockRejectedValue(new Error('timeout'))
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-testid="text-notion-account"]').text()).toBe('Notion is connected.')
    expect(wrapper.find('[data-testid="toggle-notion-write"]').exists()).toBe(true)
  })

  it('does not ask Notion for the account before it is connected', async () => {
    await mountPanel()
    expect(accountMock).not.toHaveBeenCalled()
  })

  it('shows one sentence and a connect button when Notion is not connected', async () => {
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="section-notion-connect"]').text()).toContain(
      'Connect Notion so the AI can read your pages in chat.'
    )
    expect(wrapper.get('[data-testid="btn-notion-connect"]').text()).toBe('Connect Notion')
    expect(wrapper.find('[data-testid="toggle-notion-write"]').exists()).toBe(false)
  })

  it('asks to connect again when the session expired', async () => {
    status.status = 'reauth_required'
    status.server_id = 4
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="section-notion-reauth"]').text()).toContain(
      'Notion needs to be connected again'
    )
    expect(wrapper.get('[data-testid="btn-notion-reconnect"]').text()).toBe('Connect again')
  })

  it('turns writing on and shows what the AI may and may not do', async () => {
    status.status = 'connected'
    status.server_id = 4
    setWriting.mockImplementation(async (allow: boolean) => ({
      ...status,
      status: 'connected',
      allow_write: allow,
      write_auto: allow,
    }))
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="text-notion-write-consequence"]').text()).toContain(
      'will not change anything'
    )

    await wrapper.get('[data-testid="toggle-notion-write"]').setValue(true)
    await flushPromises()

    expect(setWriting).toHaveBeenCalledWith(true)
    expect(wrapper.get('[data-testid="text-notion-write-consequence"]').text()).toContain(
      'cannot delete, move or overwrite pages'
    )
    expect(success).toHaveBeenCalled()
  })

  it('opens chat with an example and disconnects from the same card', async () => {
    status.status = 'connected'
    status.server_id = 4
    confirm.mockResolvedValue(true)
    disconnectOAuth.mockResolvedValue(undefined)
    const wrapper = await mountPanel()

    await wrapper.get('[data-testid="btn-notion-example-read"]').trigger('click')
    expect(push).toHaveBeenCalledWith({
      name: 'chat',
      query: { prefill: 'Summarize my Roadmap page' },
    })

    status.status = 'not_connected'
    status.server_id = 4
    await wrapper.get('[data-testid="btn-notion-disconnect"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalled()
    expect(disconnectOAuth).toHaveBeenCalledWith(4)
    expect(wrapper.get('[data-testid="btn-notion-connect"]').text()).toBe('Connect Notion')
  })

  it('offers the shared connection to a person without their own', async () => {
    status.sharing = { shared_by: 'Ada Lovelace', can_share: false, shared_on: false }
    useShared.mockResolvedValue(undefined)
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-testid="text-notion-shared"]').text()).toContain('Ada Lovelace')
    expect(wrapper.get('[data-testid="btn-notion-connect"]').text()).toBe('Connect your own Notion')

    await wrapper.get('[data-testid="btn-notion-use-shared"]').trigger('click')
    await flushPromises()

    expect(useShared).toHaveBeenCalled()
    expect(success).toHaveBeenCalledWith('Chat can now read Notion through the shared connection.')
    expect(push).toHaveBeenCalledWith({ name: 'chat' })
  })

  it('lets an admin share the connection read-only and says what that means', async () => {
    status.status = 'connected'
    status.server_id = 4
    status.sharing = { shared_by: null, can_share: true, shared_on: false }
    setShared.mockImplementation(async (on: boolean) => ({
      ...status,
      sharing: { shared_by: null, can_share: true, shared_on: on },
    }))
    const wrapper = await mountPanel()
    expect(wrapper.get('[data-testid="text-notion-share-consequence"]').text()).toBe(
      'Only you use this connection.'
    )

    await wrapper.get('[data-testid="toggle-notion-share"]').setValue(true)
    await flushPromises()

    expect(setShared).toHaveBeenCalledWith(true)
    expect(wrapper.get('[data-testid="text-notion-share-consequence"]').text()).toContain(
      'Nobody can change Notion through it.'
    )
  })

  it('hides sharing from people who are not admins', async () => {
    status.status = 'connected'
    status.server_id = 4
    const wrapper = await mountPanel()
    expect(wrapper.find('[data-testid="section-notion-sharing"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-notion-watches"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-notion-activity"]').exists()).toBe(true)
  })
})
