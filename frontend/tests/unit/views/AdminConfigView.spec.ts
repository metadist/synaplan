import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import AdminConfigView from '@/views/AdminConfigView.vue'
import type { ConfigSchema, ConfigValue } from '@/services/api/adminConfigApi'

/**
 * D2 / NV05: instance provider keys have exactly one editor (AI infrastructure
 * › Models & keys). System config still *reports* them, but must never render
 * a password input for a `managedBy` field — a Helm/env-injected key is
 * already "set" without any UI save.
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

const password = (section: string, managed = true) => ({
  tab: 'ai',
  section,
  type: 'password' as const,
  sensitive: true,
  description: '',
  default: '',
  source: 'database' as const,
  ...(managed ? { managedBy: 'ai-infrastructure' as const } : {}),
})

const schema: ConfigSchema = {
  tabs: {
    ai: {
      label: 'AI Services',
      sections: {
        openai: { label: 'OpenAI', fields: ['OPENAI_API_KEY'] },
        higgsfield: {
          label: 'Higgsfield',
          fields: ['HIGGSFIELD_API_KEY', 'HIGGSFIELD_API_SECRET'],
        },
        google: {
          label: 'Google',
          fields: ['GOOGLE_API_KEY', 'GOOGLE_VERTEX_ACCESS_TOKEN'],
        },
      },
    },
  },
  fields: {
    OPENAI_API_KEY: password('openai'),
    HIGGSFIELD_API_KEY: password('higgsfield'),
    HIGGSFIELD_API_SECRET: password('higgsfield'),
    GOOGLE_API_KEY: password('google'),
    // A token, not a key: stays an ordinary editable field.
    GOOGLE_VERTEX_ACCESS_TOKEN: password('google', false),
  },
}

const values: Record<string, ConfigValue> = {
  OPENAI_API_KEY: { value: '', isSet: true, isMasked: true, keySource: 'env' },
  HIGGSFIELD_API_KEY: { value: '', isSet: true, isMasked: true, keySource: 'db' },
  HIGGSFIELD_API_SECRET: { value: '', isSet: true, isMasked: true, keySource: 'db' },
  GOOGLE_API_KEY: { value: '', isSet: false, isMasked: false, keySource: 'none' },
  GOOGLE_VERTEX_ACCESS_TOKEN: { value: '', isSet: false, isMasked: false },
}

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin', component: { template: '<div />' } },
      { path: '/admin/config', component: { template: '<div />' } },
      { path: '/admin/setup', component: { template: '<div />' } },
    ],
  })
  await router.push('/admin/config?tab=ai')
  await router.isReady()
  const wrapper = mount(AdminConfigView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: true,
        UpdatePanel: true,
        ExportImportPanel: true,
        M365SetupGuide: true,
        DropboxSetupGuide: true,
        Icon: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('AdminConfigView — managed provider keys (D2)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getConfigSchema.mockResolvedValue(schema)
    getConfigValues.mockResolvedValue(values)
  })

  it('replaces an all-managed section with the status card and renders no password input', async () => {
    const wrapper = await mountView()

    const cards = wrapper.findAll('[data-testid="managed-keys-status-card"]')
    // openai + higgsfield are fully managed, google is mixed ⇒ three cards.
    expect(cards).toHaveLength(3)

    const openai = wrapper.get('[data-testid="managed-key-OPENAI_API_KEY"]')
    expect(openai.attributes('data-state')).toBe('env')
    expect(
      wrapper.get('[data-testid="managed-key-HIGGSFIELD_API_SECRET"]').attributes('data-state')
    ).toBe('db')

    // The only password input left on the tab is the (unmanaged) Vertex token.
    const passwordInputs = wrapper.findAll('input[type="password"]')
    expect(passwordInputs).toHaveLength(1)
    expect(wrapper.html()).not.toMatch(/name="OPENAI_API_KEY"|id="OPENAI_API_KEY"/)
    expect(wrapper.text()).toContain('AI infrastructure › Models & keys')
    expect(wrapper.text()).toContain('a chart install does not need this page')
  })

  it('links every card to the one editor', async () => {
    const wrapper = await mountView()

    const links = wrapper.findAll('[data-testid="managed-keys-link"]')
    expect(links.length).toBeGreaterThan(0)
    for (const link of links) expect(link.attributes('href')).toBe('/admin/setup')
  })

  it('keeps editable fields in a mixed section and lists the hidden keys below them', async () => {
    const wrapper = await mountView()

    // Vertex token field is still rendered as a ConfigField…
    expect(wrapper.text()).toContain('GOOGLE_VERTEX_ACCESS_TOKEN')
    // …while GOOGLE_API_KEY is only named in the status card, never as a field.
    const chip = wrapper.get('[data-testid="managed-key-GOOGLE_API_KEY"]')
    expect(chip.attributes('data-state')).toBe('none')
    expect(wrapper.find('input[name="GOOGLE_API_KEY"], #GOOGLE_API_KEY').exists()).toBe(false)
    expect(wrapper.text()).toContain('AI infrastructure › Models & keys')
  })

  it('folds later sections and opens them from the header or jump nav', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('#config-section-openai').attributes('data-open')).toBe('true')
    expect(wrapper.get('#config-section-higgsfield').attributes('data-open')).toBe('false')
    expect(wrapper.find('[data-testid="btn-jump-section-google"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-config-section-higgsfield"]').trigger('click')
    expect(wrapper.get('#config-section-higgsfield').attributes('data-open')).toBe('true')

    await wrapper.get('[data-testid="btn-config-accordion-toggle-all"]').trigger('click')
    expect(wrapper.get('#config-section-openai').attributes('data-open')).toBe('true')
    expect(wrapper.get('#config-section-higgsfield').attributes('data-open')).toBe('true')
    expect(wrapper.get('#config-section-google').attributes('data-open')).toBe('true')
  })
})
