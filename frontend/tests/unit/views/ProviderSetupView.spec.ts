import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ProviderSetupView from '@/views/ProviderSetupView.vue'
import type { ConfigSchema, ConfigValue } from '@/services/api/adminConfigApi'

/**
 * AI infrastructure is the one Operate page for everything the AI needs:
 * provider keys, model health, document reading, knowledge search, chat
 * behaviour and system prompts. Backend settings of the same topic render
 * on the same tab as the purpose-built controls.
 */

const getConfigSchema = vi.hoisted(() => vi.fn())
const getConfigValues = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/adminConfigApi', () => ({
  getConfigSchema,
  getConfigValues,
  updateConfigValue: vi.fn(),
  testConnection: vi.fn(),
}))

vi.mock('@/services/api/providerKeysApi', () => ({
  listProviderKeys: vi.fn().mockResolvedValue({ providers: [], defaultChatProvider: '' }),
}))

vi.mock('@/services/api/adminModelsApi', () => ({
  adminModelsApi: {
    importEndpointPreview: vi.fn().mockResolvedValue({ endpointOk: true, models: [] }),
  },
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    setup: { chatReady: false },
    reload: vi.fn().mockResolvedValue(undefined),
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/components/admin/AdminPromptsPanel.vue', () => ({
  __esModule: true,
  default: { template: '<div data-testid="prompts-panel-stub" />' },
}))

const field = (tab: string, section: string, extra: Record<string, unknown> = {}) => ({
  tab,
  section,
  type: 'text' as const,
  sensitive: false,
  description: '',
  default: '',
  ...extra,
})

const schema: ConfigSchema = {
  tabs: {
    ai: {
      label: 'AI Services',
      sections: {
        ollama: { label: 'Local AI (Ollama)', fields: ['OLLAMA_BASE_URL'] },
        cloud: {
          label: 'Cloud AI Providers',
          fields: ['OPENAI_API_KEY', 'GOOGLE_VERTEX_ACCESS_TOKEN'],
        },
        media: { label: 'Image & Video Generation', fields: ['THEHIVE_API_KEY'] },
        embeddings: { label: 'Embeddings', fields: ['CLOUDFLARE_ACCOUNT_ID'] },
        tts: { label: 'Text-to-Speech', fields: ['SYNAPLAN_TTS_URL'] },
      },
    },
    processing: {
      label: 'Processing',
      sections: {
        tika: { label: 'Apache Tika', fields: ['TIKA_BASE_URL'] },
        brave: { label: 'Web Search (Brave)', fields: ['BRAVE_SEARCH_ENABLED'] },
      },
    },
    vectordb: {
      label: 'Vector DB',
      sections: { qdrant: { label: 'Qdrant', fields: ['QDRANT_URL'] } },
    },
    routing: {
      label: 'Routing',
      sections: { multitask: { label: 'Multi-task', fields: ['MULTITASK_ROUTING_ENABLED'] } },
    },
  },
  fields: {
    OLLAMA_BASE_URL: field('ai', 'ollama', { type: 'url' }),
    OPENAI_API_KEY: field('ai', 'cloud', {
      type: 'password',
      sensitive: true,
      source: 'database',
      managedBy: 'ai-infrastructure',
    }),
    GOOGLE_VERTEX_ACCESS_TOKEN: field('ai', 'cloud', { type: 'password', sensitive: true }),
    THEHIVE_API_KEY: field('ai', 'media', {
      type: 'password',
      sensitive: true,
      source: 'database',
      managedBy: 'ai-infrastructure',
    }),
    CLOUDFLARE_ACCOUNT_ID: field('ai', 'embeddings'),
    SYNAPLAN_TTS_URL: field('ai', 'tts', { type: 'url' }),
    TIKA_BASE_URL: field('processing', 'tika', { type: 'url' }),
    BRAVE_SEARCH_ENABLED: field('processing', 'brave', { type: 'boolean' }),
    QDRANT_URL: field('vectordb', 'qdrant', { type: 'url' }),
    MULTITASK_ROUTING_ENABLED: field('routing', 'multitask', {
      type: 'boolean',
      source: 'database',
    }),
  },
}

const values: Record<string, ConfigValue> = {}

async function mountView(path = '/admin/setup') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin/setup', component: { template: '<div />' } },
      { path: '/admin/config', component: { template: '<div />' } },
      { path: '/ai/models', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ProviderSetupView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: true,
        LocalAiDownloadCard: true,
        ProviderKeyCard: true,
        ProviderHelpHint: true,
        ExtractionPlugTab: { template: '<div data-testid="extraction-plug-tab-stub" />' },
        RerankPlugTab: { template: '<div data-testid="rerank-plug-tab-stub" />' },
        ModelHealthPanel: { template: '<div data-testid="model-health-stub" />' },
        Icon: true,
      },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('ProviderSetupView — AI infrastructure', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getConfigSchema.mockResolvedValue(schema)
    getConfigValues.mockResolvedValue(values)
  })

  it('links to the Edit models tab on /ai/models', async () => {
    const { wrapper } = await mountView()

    const link = wrapper.get('[data-testid="setup-own-service"]')
    expect(link.text()).toContain('Add your own service')
    expect(link.attributes('href')).toBe('/ai/models?tab=edit')
  })

  it('groups everything AI into seven topic tabs', async () => {
    const { wrapper } = await mountView()

    const labels = [
      ['providers', 'Providers & keys'],
      ['health', 'Model health'],
      ['documents', 'Document reading'],
      ['search', 'Knowledge search'],
      ['behavior', 'Chat behavior'],
      ['prompts', 'System prompts'],
      ['gateway', 'Coding gateway'],
    ]
    for (const [id, label] of labels) {
      expect(wrapper.get(`[data-testid="admin-setup-tab-${id}"]`).text()).toContain(label)
    }
    expect(wrapper.find('[data-testid="admin-setup-tab-web-search"]').exists()).toBe(false)
  })

  it('keeps the Ollama address inside Local AI and never repeats provider keys', async () => {
    const { wrapper } = await mountView()

    const localAi = wrapper.get('[data-testid="setup-local-ai-settings"]')
    expect(localAi.text()).toContain('OLLAMA_BASE_URL')
    expect(wrapper.find('#setup-section-tts').exists()).toBe(true)
    expect(wrapper.text()).toContain('Speech output (text-to-speech)')
    // The Vertex token is the only unmanaged field of the cloud section.
    expect(wrapper.text()).toContain('GOOGLE_VERTEX_ACCESS_TOKEN')
    // Provider keys are edited on the cards, so the status card never shows here…
    expect(wrapper.find('[data-testid="managed-keys-status-card"]').exists()).toBe(false)
    // …and a section that only holds provider keys disappears.
    expect(wrapper.find('#setup-section-media').exists()).toBe(false)
    // Web search is a platform setting and lives on System configuration.
    expect(wrapper.text()).not.toContain('BRAVE_SEARCH_ENABLED')
  })

  it('shows reading services next to the extraction chains', async () => {
    const { wrapper } = await mountView('/admin/setup?tab=documents')

    expect(wrapper.find('[data-testid="extraction-plug-tab-stub"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Reading services')
    expect(wrapper.find('#config-section-tika').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-config-test-tika"]').exists()).toBe(true)
  })

  it('shows embeddings, the vector database and reranking on Knowledge search', async () => {
    const { wrapper } = await mountView('/admin/setup?tab=search')

    expect(wrapper.find('#config-section-embeddings').exists()).toBe(true)
    expect(wrapper.find('#config-section-qdrant').exists()).toBe(true)
    expect(wrapper.text()).toContain('Vector database (Qdrant)')
    expect(wrapper.find('[data-testid="rerank-plug-tab-stub"]').exists()).toBe(true)
  })

  it('opens the section a deep link names', async () => {
    const { wrapper } = await mountView('/admin/setup?tab=documents&section=tika')

    expect(wrapper.get('#config-section-tika').attributes('data-open')).toBe('true')
  })

  it('switches tabs, keeps other query params and drops a stale section', async () => {
    const { wrapper, router } = await mountView('/admin/setup?connected=1')

    await wrapper.get('[data-testid="admin-setup-tab-documents"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1', tab: 'documents' })

    await router.replace({ query: { connected: '1', tab: 'documents', section: 'tika' } })
    await wrapper.get('[data-testid="admin-setup-tab-health"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1', tab: 'health' })
    expect(wrapper.find('[data-testid="model-health-stub"]').exists()).toBe(true)

    await wrapper.get('[data-testid="admin-setup-tab-prompts"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="prompts-panel-stub"]').exists()).toBe(true)

    await wrapper.get('[data-testid="admin-setup-tab-providers"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1' })
  })

  it('offers a retry when the settings cannot be loaded', async () => {
    getConfigSchema.mockRejectedValue(new Error('down'))
    const { wrapper } = await mountView('/admin/setup?tab=behavior')

    expect(wrapper.find('[data-testid="ai-settings-load-error"]').exists()).toBe(true)
  })
})
