import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import McpServersConfiguration from '@/components/config/McpServersConfiguration.vue'

/**
 * The "task usage" panel closes the worst MCP onboarding gap: a connected
 * server does NOTHING until at least one routing topic opts in via its
 * `tool_mcp` prompt metadata. The panel must (a) warn when servers are
 * connected but unused, and (b) let the user flip the per-task opt-in right
 * on the connections page.
 */

const mockServer = {
  id: 3,
  name: 'Knowledge Base One',
  url: 'https://web.synaplan.com/mcp',
  auth_header: 'X-API-Key',
  has_auth_token: true,
  enabled: true,
}

const defaultPrompt = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  topic: 'general',
  name: 'General Chat',
  shortDescription: 'Default chat topic',
  prompt: 'You are a helpful assistant.',
  language: 'en',
  isDefault: true,
  isUserOverride: false,
  selectionRules: null,
  metadata: { aiModel: 0, tool_files: true },
  ...overrides,
})

const {
  mockList,
  mockGetPrompts,
  mockCreatePrompt,
  mockUpdatePrompt,
  mockUpdateConfigValue,
  authState,
} = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockGetPrompts: vi.fn(),
  mockCreatePrompt: vi.fn(),
  mockUpdatePrompt: vi.fn(),
  mockUpdateConfigValue: vi.fn(),
  authState: { isAdmin: false },
}))

vi.mock('@/services/api/mcpServersApi', () => ({
  mcpServersApi: {
    list: mockList,
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    test: vi.fn(),
    tools: vi.fn(),
    startOAuth: vi.fn(),
    disconnectOAuth: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  RouterLink: { template: '<a><slot /></a>' },
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: vi.fn() }),
}))

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPrompts: mockGetPrompts,
    createPrompt: mockCreatePrompt,
    updatePrompt: mockUpdatePrompt,
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))

vi.mock('@/services/api/adminConfigApi', () => ({
  updateConfigValue: mockUpdateConfigValue,
}))

const mountOptions = {
  global: {
    stubs: {
      Icon: true,
      RouterLink: { template: '<a><slot /></a>' },
    },
  },
}

describe('McpServersConfiguration — task usage panel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isAdmin = false
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: false,
      servers: [mockServer],
    })
    mockGetPrompts.mockResolvedValue([defaultPrompt()])
    mockUpdateConfigValue.mockResolvedValue({ success: true })
  })

  it('warns when servers are connected but no task allows MCP data sources', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-mcp-usage"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="mcp-usage-warning"]').exists()).toBe(true)
  })

  it('hides the warning once a task has MCP data sources enabled', async () => {
    mockGetPrompts.mockResolvedValue([defaultPrompt({ metadata: { aiModel: 0, tool_mcp: true } })])

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-usage-warning"]').exists()).toBe(false)
    const toggle = wrapper.find('[data-testid="toggle-mcp-topic-general"]')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
  })

  it('hides the whole panel when no servers are connected', async () => {
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: false,
      servers: [],
    })

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-mcp-usage"]').exists()).toBe(false)
  })

  it('excludes widget assistants (w_*) from the task list', async () => {
    mockGetPrompts.mockResolvedValue([
      defaultPrompt(),
      defaultPrompt({ id: 22, topic: 'w_66ed0d2f9691af', name: 'Widget assistant' }),
    ])

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-usage-general"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="mcp-usage-w_66ed0d2f9691af"]').exists()).toBe(false)
  })

  it('creates a personal override when a plain user enables MCP on a system default', async () => {
    mockCreatePrompt.mockResolvedValue(
      defaultPrompt({
        id: 40,
        isDefault: false,
        metadata: { aiModel: 0, tool_files: true, tool_mcp: true },
      })
    )

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="toggle-mcp-topic-general"]').trigger('change')
    await flushPromises()

    expect(mockCreatePrompt).toHaveBeenCalledWith(
      expect.objectContaining({
        topic: 'general',
        metadata: expect.objectContaining({ tool_mcp: true }),
      })
    )
    expect(mockUpdatePrompt).not.toHaveBeenCalled()
    // The warning disappears once the opt-in landed.
    expect(wrapper.find('[data-testid="mcp-usage-warning"]').exists()).toBe(false)
  })

  it('updates in place when the prompt is a user override', async () => {
    mockGetPrompts.mockResolvedValue([
      defaultPrompt({ isUserOverride: true, metadata: { aiModel: 0, tool_mcp: true } }),
    ])
    mockUpdatePrompt.mockResolvedValue(
      defaultPrompt({ isUserOverride: true, metadata: { aiModel: 0, tool_mcp: false } })
    )

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="toggle-mcp-topic-general"]').trigger('change')
    await flushPromises()

    expect(mockUpdatePrompt).toHaveBeenCalledWith(
      1,
      expect.objectContaining({
        metadata: expect.objectContaining({ tool_mcp: false }),
      })
    )
    expect(mockCreatePrompt).not.toHaveBeenCalled()
  })
})

describe('McpServersConfiguration — platform MCP switch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isAdmin = true
    mockList.mockResolvedValue({
      clientEnabled: false,
      oauthConnectorsEnabled: false,
      servers: [],
    })
    mockGetPrompts.mockResolvedValue([])
    mockUpdateConfigValue.mockResolvedValue({ success: true })
  })

  it('explains that calls are off and lets an admin turn them on', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-client-status"]').text()).toContain('MCP calls are off')
    expect(wrapper.find('[data-testid="link-mcp-system-config"]').exists()).toBe(true)
    const enable = wrapper.find('[data-testid="btn-mcp-enable-client"]')
    expect(enable.exists()).toBe(true)

    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: false,
      servers: [],
    })
    await enable.trigger('click')
    await flushPromises()

    expect(mockUpdateConfigValue).toHaveBeenCalledWith('MCP_CLIENT_ENABLED', 'true')
    expect(wrapper.find('[data-testid="toggle-mcp-client"]').exists()).toBe(true)
  })

  it('tells a non-admin to ask an administrator, without a switch', async () => {
    authState.isAdmin = false
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-mcp-enable-client"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="toggle-mcp-client"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="mcp-client-status"]').text()).toContain(
      'An administrator needs to turn this on'
    )
  })
})

describe('McpServersConfiguration — add-server templates', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isAdmin = false
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: false,
      servers: [],
    })
    mockGetPrompts.mockResolvedValue([])
  })

  it('shows a template catalog on the empty page, with Custom selected', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-empty"]').exists()).toBe(true)
    const custom = wrapper.find('[data-testid="btn-mcp-template-custom"]')
    const jira = wrapper.find('[data-testid="btn-mcp-template-jira"]')
    expect(custom.exists()).toBe(true)
    expect(jira.exists()).toBe(true)
    expect(custom.attributes('aria-checked')).toBe('true')
    expect(jira.attributes('aria-checked')).toBe('false')
  })

  it('opens the form from a template and prefills only that template', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="btn-mcp-template-jira"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="section-mcp-editor"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="mcp-empty"]').exists()).toBe(false)
    const name = wrapper.find('[data-testid="input-mcp-name"]').element as HTMLInputElement
    expect(name.value).toBe('Jira')
    expect(wrapper.find('[data-testid="btn-mcp-template-jira"]').attributes('aria-checked')).toBe(
      'true'
    )
  })

  it('clears the named template when it is clicked again', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="btn-mcp-add"]').trigger('click')
    await wrapper.find('[data-testid="btn-mcp-template-jira"]').trigger('click')
    await flushPromises()

    const name = () => wrapper.find('[data-testid="input-mcp-name"]').element as HTMLInputElement
    expect(name().value).toBe('Jira')

    await wrapper.find('[data-testid="btn-mcp-template-jira"]').trigger('click')
    await flushPromises()

    expect(name().value).toBe('')
    expect(wrapper.find('[data-testid="btn-mcp-template-custom"]').attributes('aria-checked')).toBe(
      'true'
    )
    expect(wrapper.find('[data-testid="btn-mcp-template-jira"]').attributes('aria-checked')).toBe(
      'false'
    )
  })

  it('hides Notion and Higgsfield until OAuth connectors are enabled', async () => {
    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-mcp-template-notion"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-mcp-template-higgsfield"]').exists()).toBe(false)
  })

  it('prefills and locks the Notion URL when OAuth connectors are on', async () => {
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: true,
      servers: [],
    })

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-mcp-template-notion"]').exists()).toBe(true)
    await wrapper.find('[data-testid="btn-mcp-template-notion"]').trigger('click')
    await flushPromises()

    const url = wrapper.find('[data-testid="input-mcp-url"]').element as HTMLInputElement
    expect(url.value).toBe('https://mcp.notion.com/mcp')
    expect(url.readOnly).toBe(true)
    expect(wrapper.find('[data-testid="input-mcp-auth-token"]').exists()).toBe(false)
  })
})

describe('McpServersConfiguration — OAuth status chips', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.isAdmin = false
    mockGetPrompts.mockResolvedValue([])
  })

  it('shows Connect for a saved OAuth server that is not signed in', async () => {
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: true,
      servers: [
        {
          ...mockServer,
          auth_mode: 'oauth',
          oauth_status: 'not_connected',
          has_auth_token: false,
        },
      ],
    })

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-oauth-status-3"]').text()).toContain('Not connected')
    expect(wrapper.find('[data-testid="btn-mcp-oauth-3"]').text()).toContain('Connect')
  })

  it('shows Reconnect when the grant needs a new sign-in', async () => {
    mockList.mockResolvedValue({
      clientEnabled: true,
      oauthConnectorsEnabled: true,
      servers: [
        {
          ...mockServer,
          auth_mode: 'oauth',
          oauth_status: 'reauth_required',
        },
      ],
    })

    const wrapper = mount(McpServersConfiguration, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="mcp-oauth-status-3"]').text()).toContain('Action needed')
    expect(wrapper.find('[data-testid="btn-mcp-oauth-3"]').text()).toContain('Reconnect')
  })
})
