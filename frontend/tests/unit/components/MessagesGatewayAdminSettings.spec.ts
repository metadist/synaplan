import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessagesGatewayAdminSettings from '@/components/config/messagesGateway/MessagesGatewayAdminSettings.vue'
import type { MessagesGatewayStatus } from '@/services/api/messagesGatewayApi'

/**
 * The AI Agents settings panel writes one setting per control, so a patch that
 * carries more than the changed key would silently reset a neighbouring
 * setting. It also has to make a setting that cannot take effect obviously
 * inert instead of letting an admin toggle something with no consequence.
 */

const { mockSaveFlags } = vi.hoisted(() => ({
  mockSaveFlags: vi.fn(),
}))

vi.mock('@/services/api/messagesGatewayApi', () => ({
  saveMessagesGatewayFlags: mockSaveFlags,
  saveMessagesGatewayAliases: vi.fn(),
  saveMessagesGatewayUpstream: vi.fn(),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const baseStatus = (overrides: Partial<MessagesGatewayStatus> = {}): MessagesGatewayStatus =>
  ({
    enabled: true,
    allow_operator_key: false,
    mcp_tools_enabled: false,
    mcp_tools_with_client_tools: false,
    mcp_max_iterations: 8,
    mcp_servers_configured: 0,
    web_search_mode: 'auto',
    web_search_available: true,
    vision_mode: 'auto',
    vision_available: true,
    vision_image_detail: 'auto',
    vision_max_images: 0,
    context_injection_enabled: false,
    budget_notice_enabled: true,
    session_summary_enabled: true,
    upstream_url: 'https://api.anthropic.com',
    model_aliases: {},
    keys: {},
    budget: {},
    is_admin: true,
    setup: {},
    ...overrides,
  }) as MessagesGatewayStatus

const mountPanel = (status: MessagesGatewayStatus = baseStatus()) =>
  mount(MessagesGatewayAdminSettings, {
    props: { status },
    global: { stubs: { Icon: true } },
  })

describe('MessagesGatewayAdminSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockSaveFlags.mockResolvedValue({ success: true, updated: {} })
  })

  it('sends only the setting that changed', async () => {
    const wrapper = mountPanel()

    await wrapper.find('[data-testid="toggle-agents-mcp-tools"] button').trigger('click')
    await flushPromises()

    expect(mockSaveFlags).toHaveBeenCalledWith({ mcp_tools_enabled: true })
  })

  it('disables the MCP follow-up settings until MCP tools are on', async () => {
    const wrapper = mountPanel()

    expect(
      wrapper
        .find('[data-testid="toggle-agents-mcp-with-client-tools"] button')
        .attributes('disabled')
    ).toBeDefined()
    expect(
      wrapper.find('[data-testid="input-agents-tool-rounds"]').attributes('disabled')
    ).toBeDefined()

    const enabled = mountPanel(baseStatus({ mcp_tools_enabled: true }))
    expect(
      enabled.find('[data-testid="input-agents-tool-rounds"]').attributes('disabled')
    ).toBeUndefined()
  })

  it('offers Synaplan vision only when a vision model is configured', () => {
    const unavailable = mountPanel(baseStatus({ vision_available: false }))
    const option = unavailable.find(
      '[data-testid="select-agents-vision-mode"] option[value=synaplan]'
    )
    expect(option.attributes('disabled')).toBeDefined()

    const available = mountPanel()
    expect(
      available
        .find('[data-testid="select-agents-vision-mode"] option[value=synaplan]')
        .attributes('disabled')
    ).toBeUndefined()
  })

  it('falls back to automatic when the stored mode lost its provider', async () => {
    const wrapper = mountPanel(baseStatus({ vision_mode: 'synaplan', vision_available: false }))
    await flushPromises()

    expect(mockSaveFlags).toHaveBeenCalledWith({ vision_mode: 'auto' })
    expect(
      (wrapper.find('[data-testid="select-agents-vision-mode"]').element as HTMLSelectElement).value
    ).toBe('auto')
  })

  it('keeps the image cost controls usable in every vision mode', () => {
    const wrapper = mountPanel(baseStatus({ vision_mode: 'off' }))

    expect(
      wrapper.find('[data-testid="select-agents-image-detail"]').attributes('disabled')
    ).toBeUndefined()
    expect(
      wrapper.find('[data-testid="input-agents-max-images"]').attributes('disabled')
    ).toBeUndefined()
  })

  it('clamps an out-of-range image cap before saving it', async () => {
    const wrapper = mountPanel()
    const input = wrapper.find('[data-testid="input-agents-max-images"]')

    await input.setValue('4000')
    await input.trigger('change')
    await flushPromises()

    expect(mockSaveFlags).toHaveBeenCalledWith({ vision_max_images: 100 })
    expect((input.element as HTMLInputElement).value).toBe('100')
  })

  it('does not save a number that did not change', async () => {
    const wrapper = mountPanel(baseStatus({ mcp_tools_enabled: true }))
    const input = wrapper.find('[data-testid="input-agents-tool-rounds"]')

    await input.setValue('8')
    await input.trigger('change')
    await flushPromises()

    expect(mockSaveFlags).not.toHaveBeenCalled()
  })

  it('points out that no tool server is connected yet', () => {
    const wrapper = mountPanel()

    expect(wrapper.text()).toContain('No tool server is connected yet')
  })

  it('warns that settings do nothing while the gateway is off', () => {
    const wrapper = mountPanel(baseStatus({ enabled: false }))

    expect(wrapper.find('[data-testid="notice-agents-disabled"]').exists()).toBe(true)
  })
})
