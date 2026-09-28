import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessagesGatewayConfiguration from '@/components/config/MessagesGatewayConfiguration.vue'
import type { MessagesGatewayStatus } from '@/services/api/messagesGatewayApi'

const { mockGetStatus, apiBaseUrl } = vi.hoisted(() => ({
  mockGetStatus: vi.fn(),
  apiBaseUrl: { value: '' },
}))

vi.mock('@/services/api/messagesGatewayApi', () => ({
  getMessagesGatewayStatus: mockGetStatus,
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get apiBaseUrl() {
      return apiBaseUrl.value
    },
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const status = (overrides: Partial<MessagesGatewayStatus> = {}): MessagesGatewayStatus =>
  ({
    enabled: true,
    allow_operator_key: false,
    mcp_tools_enabled: false,
    mcp_tools_with_client_tools: false,
    mcp_max_iterations: 8,
    mcp_servers_configured: 0,
    server_tools: [],
    web_search_mode: 'auto',
    web_search_available: false,
    web_fetch_mode: 'auto',
    vision_mode: 'auto',
    vision_available: false,
    vision_image_detail: 'auto',
    vision_max_images: 0,
    context_injection_enabled: false,
    budget_notice_enabled: true,
    session_summary_enabled: true,
    upstream_url: 'https://api.anthropic.com',
    model_aliases: {},
    keys: {
      anthropic: {
        has_user_key: false,
        user_key_masked: '',
        has_operator_key: false,
        effective_source: 'none',
      },
    },
    budget: { percent: 0, used_cost: '0', budget: '0', remaining: '0', allowed: true },
    is_admin: false,
    app_chat_credential: 'missing',
    setup: {
      base_url_hint: 'http://localhost:8000',
      env_api_key: 'ANTHROPIC_API_KEY',
      env_auth_token: 'ANTHROPIC_AUTH_TOKEN',
      note: '',
    },
    ...overrides,
  }) as MessagesGatewayStatus

const mountPage = async () => {
  const wrapper = mount(MessagesGatewayConfiguration, {
    global: {
      stubs: {
        Icon: true,
        PageHeader: true,
        MessagesGatewayAdminSettings: true,
        RouterLink: {
          props: ['to'],
          template: "<a :href=\"typeof to === 'string' ? to : ''\"><slot /></a>",
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('MessagesGatewayConfiguration', () => {
  beforeEach(() => {
    mockGetStatus.mockReset()
    apiBaseUrl.value = ''
  })

  it('copies the API origin, not the page origin, when no runtime API base is set', async () => {
    mockGetStatus.mockResolvedValue(status())
    const wrapper = await mountPage()

    expect(wrapper.get('[data-testid="text-setup-snippet"]').text()).toContain(
      'ANTHROPIC_BASE_URL="http://localhost:8000"'
    )
    expect(wrapper.get('[data-testid="badge-gateway-enabled"]').text()).toContain('Not ready')
    expect(wrapper.get('[data-testid="text-missing-key"]').text()).toContain('Your AI accounts')
    expect(wrapper.get('[data-testid="text-missing-key"]').text()).toContain('server key')
    expect(wrapper.get('[data-testid="link-api-keys"]').attributes('href')).toBe('/channels/api')
  })

  it('hides setup commands from a non-admin while the gateway is off', async () => {
    mockGetStatus.mockResolvedValue(status({ enabled: false }))
    const wrapper = await mountPage()

    expect(wrapper.get('[data-testid="text-gateway-off"]').text()).toContain('nothing is sent')
    expect(wrapper.find('[data-testid="btn-copy-setup"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-agents-setup"]').exists()).toBe(false)
  })

  it('shows Enabled once a provider key will pay', async () => {
    mockGetStatus.mockResolvedValue(
      status({
        is_admin: true,
        keys: {
          anthropic: {
            has_user_key: true,
            user_key_masked: 'sk-ant-****',
            has_operator_key: false,
            effective_source: 'user',
          },
        },
      })
    )
    const wrapper = await mountPage()

    expect(wrapper.get('[data-testid="badge-gateway-enabled"]').text()).toContain('Enabled')
    expect(wrapper.find('[data-testid="text-missing-key"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-copy-setup"]').exists()).toBe(true)
  })
})
