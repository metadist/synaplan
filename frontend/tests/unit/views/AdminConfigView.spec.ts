import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import AdminConfigView from '@/views/AdminConfigView.vue'
import type { ConfigSchema, ConfigValue } from '@/services/api/adminConfigApi'

/**
 * System configuration holds the platform settings, grouped by topic
 * (Access · Features & tools · Channels & apps · Appearance). Everything the
 * AI needs lives on AI infrastructure, so none of those sections render here,
 * and instance provider keys are never an input on this page (D2 / NV05).
 */

const getConfigSchema = vi.hoisted(() => vi.fn())
const getConfigValues = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/adminConfigApi', () => ({
  getConfigSchema,
  getConfigValues,
  updateConfigValue: vi.fn(),
  testConnection: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isAdmin: true }) }))
vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ reload: vi.fn().mockResolvedValue(undefined) }),
}))
vi.mock('@/stores/updates', () => ({ useUpdatesStore: () => ({ canRead: false }) }))
vi.mock('@/composables/useTheme', () => ({ useTheme: () => ({ isDark: { value: false } }) }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/services/api/nativeHaptics', () => ({ triggerHapticImpact: vi.fn() }))

const field = (tab: string, section: string, extra: Record<string, unknown> = {}) => ({
  tab,
  section,
  type: 'text' as const,
  sensitive: false,
  description: '',
  default: '',
  ...extra,
})

const managedKey = (tab: string, section: string) =>
  field(tab, section, {
    type: 'password',
    sensitive: true,
    source: 'database',
    managedBy: 'ai-infrastructure',
  })

const schema: ConfigSchema = {
  tabs: {
    ai: {
      label: 'AI Services',
      sections: {
        ollama: { label: 'Local AI (Ollama)', fields: ['OLLAMA_BASE_URL'] },
        cloud: { label: 'Cloud AI Providers', fields: ['OPENAI_API_KEY'] },
      },
    },
    email: {
      label: 'Email',
      sections: { mailer: { label: 'Primary Mailer', fields: ['MAILER_DSN'] } },
    },
    auth: {
      label: 'Authentication',
      sections: {
        access: { label: 'Who can use this instance', fields: ['REGISTRATION_ENABLED'] },
        google: { label: 'Google OAuth 2.0', fields: ['GOOGLE_CLIENT_ID', 'GOOGLE_API_KEY'] },
      },
    },
    channels: {
      label: 'Inbound Channels',
      sections: { whatsapp: { label: 'WhatsApp Business API', fields: ['WHATSAPP_ENABLED'] } },
    },
    processing: {
      label: 'Processing',
      sections: {
        tika: { label: 'Apache Tika', fields: ['TIKA_BASE_URL'] },
        brave: { label: 'Web Search (Brave)', fields: ['BRAVE_SEARCH_API_KEY'] },
        compute: { label: 'File work', fields: ['COMPUTE_ENABLED'] },
      },
    },
    routing: {
      label: 'Routing',
      sections: {
        multitask: { label: 'Multi-task routing', fields: ['MULTITASK_ROUTING_ENABLED'] },
        tools: { label: 'Tool policies', fields: ['TOOLS_POLICY_READ'] },
      },
    },
    experimental: {
      label: 'Experimental',
      sections: { lab: { label: 'Lab', fields: ['LAB_FLAG'] } },
    },
  },
  fields: {
    OLLAMA_BASE_URL: field('ai', 'ollama', { type: 'url' }),
    OPENAI_API_KEY: managedKey('ai', 'cloud'),
    MAILER_DSN: field('email', 'mailer'),
    REGISTRATION_ENABLED: field('auth', 'access', { type: 'boolean', source: 'database' }),
    GOOGLE_CLIENT_ID: field('auth', 'google'),
    GOOGLE_API_KEY: managedKey('auth', 'google'),
    WHATSAPP_ENABLED: field('channels', 'whatsapp', { type: 'boolean' }),
    TIKA_BASE_URL: field('processing', 'tika', { type: 'url' }),
    BRAVE_SEARCH_API_KEY: field('processing', 'brave', { type: 'password', sensitive: true }),
    COMPUTE_ENABLED: field('processing', 'compute', { type: 'boolean' }),
    MULTITASK_ROUTING_ENABLED: field('routing', 'multitask', { type: 'boolean' }),
    TOOLS_POLICY_READ: field('routing', 'tools', { type: 'select', options: ['auto', 'approve'] }),
    LAB_FLAG: field('experimental', 'lab', { type: 'boolean' }),
  },
}

const values: Record<string, ConfigValue> = {
  OPENAI_API_KEY: { value: '', isSet: true, isMasked: true, keySource: 'env' },
  GOOGLE_API_KEY: { value: '', isSet: false, isMasked: false, keySource: 'none' },
}

async function mountView(path = '/admin/config') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin', component: { template: '<div />' } },
      { path: '/admin/config', component: { template: '<div />' } },
      { path: '/admin/setup', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(AdminConfigView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: true,
        UpdatePanel: true,
        M365SetupGuide: true,
        DropboxSetupGuide: true,
        WebSearchPlugTab: { template: '<div data-testid="web-search-plug-tab-stub" />' },
        Icon: true,
      },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

const tabIds = (wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], group: string) =>
  wrapper
    .get(`[data-testid="config-group-${group}"]`)
    .findAll('button')
    .map((button) => button.attributes('data-testid'))

describe('AdminConfigView — topics', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getConfigSchema.mockResolvedValue(schema)
    getConfigValues.mockResolvedValue(values)
  })

  it('lists every tab under its topic, all visible at once', async () => {
    const { wrapper } = await mountView()

    expect(tabIds(wrapper, 'access')).toEqual(['btn-config-tab-auth'])
    expect(tabIds(wrapper, 'features')).toEqual([
      'btn-config-tab-web_search',
      'btn-config-tab-tools',
    ])
    expect(tabIds(wrapper, 'channels')).toEqual(['btn-config-tab-email', 'btn-config-tab-channels'])
    expect(wrapper.get('[data-testid="btn-config-tab-channels"]').text()).toContain(
      'Channels & integrations'
    )
  })

  it('shows no AI settings and points to AI infrastructure instead', async () => {
    const { wrapper } = await mountView()

    for (const tab of ['ai', 'processing', 'routing', 'vectordb']) {
      expect(wrapper.find(`[data-testid="btn-config-tab-${tab}"]`).exists()).toBe(false)
    }
    const pointer = wrapper.get('[data-testid="config-ai-pointer-link"]')
    expect(pointer.attributes('href')).toBe('/admin/setup')
    expect(wrapper.get('[data-testid="config-ai-pointer"]').text()).toContain('AI infrastructure')
  })

  it('opens the first topic tab by default', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="config-tab-auth"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Who may register and sign in')
  })

  it('pairs the web search picker with the Brave settings', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.get('[data-testid="btn-config-tab-web_search"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.tab).toBe('web_search')
    expect(wrapper.find('[data-testid="web-search-plug-tab-stub"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Brave Search settings')
    expect(wrapper.find('#config-section-brave').exists()).toBe(true)
  })

  it('collects tool rules and file work under Tools & automation', async () => {
    const { wrapper } = await mountView('/admin/config?tab=tools&section=compute')

    expect(wrapper.find('#config-section-tools').exists()).toBe(true)
    expect(wrapper.get('#config-section-compute').attributes('data-open')).toBe('true')
    expect(wrapper.text()).toContain('Tool approval rules')
  })

  it('keeps a section no topic claims reachable under More settings', async () => {
    const { wrapper } = await mountView()

    expect(tabIds(wrapper, 'more')).toEqual(['btn-config-tab-more_experimental'])
    await wrapper.get('[data-testid="btn-config-tab-more_experimental"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('LAB_FLAG')
  })

  it('reports a provider key in a platform section read-only, never as an input', async () => {
    const { wrapper } = await mountView('/admin/config?tab=auth&section=google')

    const chip = wrapper.get('[data-testid="managed-key-GOOGLE_API_KEY"]')
    expect(chip.attributes('data-state')).toBe('none')
    expect(wrapper.find('input[name="GOOGLE_API_KEY"], #GOOGLE_API_KEY').exists()).toBe(false)
    expect(wrapper.text()).toContain('AI infrastructure › Providers & keys')
    expect(wrapper.get('[data-testid="managed-keys-link"]').attributes('href')).toBe('/admin/setup')
  })

  it('offers a connection test next to the mail server settings', async () => {
    const { wrapper } = await mountView('/admin/config?tab=email')

    expect(wrapper.find('[data-testid="btn-config-test-mailer"]').exists()).toBe(true)
  })

  it('folds sections and opens them from the header or jump nav', async () => {
    const { wrapper } = await mountView('/admin/config?tab=auth')

    expect(wrapper.get('#config-section-access').attributes('data-open')).toBe('false')
    expect(wrapper.find('[data-testid="btn-jump-section-google"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-config-section-access"]').trigger('click')
    expect(wrapper.get('#config-section-access').attributes('data-open')).toBe('true')

    await wrapper.get('[data-testid="btn-config-accordion-toggle-all"]').trigger('click')
    expect(wrapper.get('#config-section-google').attributes('data-open')).toBe('true')
  })

  it('finds a setting by key and opens its section', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.get('[data-testid="input-admin-config-search"]').setValue('mailer_dsn')
    const hits = wrapper.findAll('[data-testid="item-admin-config-hit"]')
    expect(hits).toHaveLength(1)
    await hits[0]!.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toMatchObject({ tab: 'email', section: 'mailer' })
  })

  it('sends a hit that lives on AI infrastructure there', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.get('[data-testid="input-admin-config-search"]').setValue('OLLAMA_BASE_URL')
    await wrapper.get('[data-testid="item-admin-config-hit"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/setup')
  })

  it('shows one sentence and a clear action when nothing matches', async () => {
    const { wrapper } = await mountView()

    const input = wrapper.get('[data-testid="input-admin-config-search"]')
    await input.setValue('zzz-nothing')
    expect(wrapper.find('[data-testid="state-admin-config-no-hits"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-admin-config-clear-search"]').trigger('click')
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(wrapper.find('[data-testid="config-group-access"]').exists()).toBe(true)
  })

  it('shows one sentence and a retry when the settings cannot be loaded', async () => {
    getConfigSchema.mockRejectedValue(new Error('down'))
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="config-load-error"]').exists()).toBe(true)
  })
})
