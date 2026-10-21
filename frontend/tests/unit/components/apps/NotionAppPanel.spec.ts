import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import NotionAppPanel from '@/components/apps/panels/NotionAppPanel.vue'

const status = {
  success: true,
  status: 'not_connected' as 'not_connected' | 'connected' | 'reauth_required',
  server_id: null as number | null,
  allow_write: false,
  write_auto: false,
}

const { statusMock, setWriting, connect, disconnectOAuth, confirm, success, error, push } =
  vi.hoisted(() => ({
    statusMock: vi.fn(),
    setWriting: vi.fn(),
    connect: vi.fn(),
    disconnectOAuth: vi.fn(),
    confirm: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    push: vi.fn(),
  }))

vi.mock('@/services/api/notionAppApi', () => ({
  notionAppApi: { status: statusMock, setWriting, connect },
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
    statusMock.mockImplementation(async () => ({ ...status }))
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
      'cannot delete or move pages'
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
})
