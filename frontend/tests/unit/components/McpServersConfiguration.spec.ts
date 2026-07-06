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

const { mockList, mockGetPrompts, mockCreatePrompt, mockUpdatePrompt } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockGetPrompts: vi.fn(),
  mockCreatePrompt: vi.fn(),
  mockUpdatePrompt: vi.fn(),
}))

vi.mock('@/services/api/mcpServersApi', () => ({
  mcpServersApi: {
    list: mockList,
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    test: vi.fn(),
    tools: vi.fn(),
  },
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
  useAuthStore: () => ({ isAdmin: false }),
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
    mockList.mockResolvedValue({ clientEnabled: true, servers: [mockServer] })
    mockGetPrompts.mockResolvedValue([defaultPrompt()])
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
    mockList.mockResolvedValue({ clientEnabled: true, servers: [] })

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
