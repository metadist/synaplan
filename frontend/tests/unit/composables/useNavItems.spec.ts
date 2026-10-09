import { defineComponent } from 'vue'
import { describe, expect, it, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import {
  canSeeManage,
  canSeeOperate,
  groupNavChildren,
  hasNestedNavGroups,
  isNavChildActive,
  setModelsNeedingAttention,
  useNavItems,
} from '@/composables/useNavItems'
import { useAuthStore, type User } from '@/stores/auth'
import { resetAiAccountsGatewayCache } from '@/composables/useAiAccounts'

const getMessagesGatewayStatus = vi.fn()

vi.mock('@/services/api/messagesGatewayApi', () => ({
  getMessagesGatewayStatus: () => getMessagesGatewayStatus(),
}))

const runtimeFeatures: Record<string, boolean> = {
  savedTasks: true,
  iamGroups: false,
  agentsEnabled: false,
  platformLinksEnabled: false,
  toolsApprovalsEnabled: false,
  desktopAgentEnabled: false,
}

const runtimeModules: Record<string, { configured?: boolean }> = {}

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
  getApiBaseUrl: () => 'http://localhost:8000',
  getConfigSync: () => ({ features: runtimeFeatures, modules: runtimeModules }),
}))

const pluginList: { name: string }[] = []

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get plugins() {
      return pluginList
    },
    billing: { enabled: false },
    reload: vi.fn(),
  }),
}))

const navMessages = {
  nav: {
    history: 'History',
    historyDescription: 'Chat history',
    files: 'Sources',
    filesDescription: 'Sources',
    manage: 'Manage',
    manageDescription: 'Manage',
    groupAssistants: 'Assistants',
    groupAutomations: 'Automations',
    groupTools: 'Tools',
    channels: 'Channels',
    connections: 'Connections',
    desktop: 'Synaplan Desktop',
    groupApi: 'API',
    myGroups: 'My groups',
    configInbound: 'Inbound',
    toolsChatWidget: 'Chat widgets',
    groupApps: 'Apps',
    allApps: 'All apps',
    connectedApps: 'Connected',
    prompts: 'Shortcuts',
    savedTasks: 'Tasks',
    approvals: 'Approvals',
    assistants: 'Assistants',
    configAiModels: 'AI settings',
    plugins: 'Plugins',
    admin: 'Admin',
    adminDashboard: 'Overview',
    adminFeatureStatus: 'System status',
    adminProviderSetup: 'AI infrastructure',
    adminSystemConfig: 'System configuration',
    adminPeople: 'People',
    adminPartners: 'Partners',
  },
  pageTitles: {
    configApiDocs: 'API docs',
  },
  common: { unknown: 'Unknown' },
}

function mountNav(user: { email: string; level: string; isAdmin?: boolean } | null) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = user
    ? ({
        id: 1,
        email: user.email,
        level: user.level,
        isAdmin: user.isAdmin ?? user.level === 'ADMIN',
      } as User)
    : null

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: navMessages },
  })

  const Harness = defineComponent({
    setup() {
      return useNavItems()
    },
    template: '<div />',
  })

  return mount(Harness, {
    global: {
      plugins: [router, i18n],
    },
  })
}

describe('useNavItems predicates', () => {
  it('shows Manage only when signed in', () => {
    expect(canSeeManage(false)).toBe(false)
    expect(canSeeManage(true)).toBe(true)
  })

  it('shows Operate only for admins', () => {
    expect(canSeeOperate(false)).toBe(false)
    expect(canSeeOperate(true)).toBe(true)
  })
})

describe('isNavChildActive', () => {
  it('matches the section-overview child only on the exact path', () => {
    const inbound = { key: 'inbound', path: '/channels', label: 'Inbound' }
    expect(isNavChildActive(inbound, '/channels', '/channels')).toBe(true)
    expect(isNavChildActive(inbound, '/channels', '/channels/widgets')).toBe(false)
  })
})

describe('groupNavChildren', () => {
  it('keeps consecutive group keys together', () => {
    const groups = groupNavChildren([
      { key: 'a', path: '/a', label: 'A', group: 'Assistants', groupKey: 'assistants' },
      { key: 'b', path: '/b', label: 'B', group: 'Channels', groupKey: 'channels' },
    ])
    expect(groups.map((g) => g.key)).toEqual(['assistants', 'channels'])
    expect(groups.map((g) => g.group)).toEqual(['Assistants', 'Channels'])
  })
})

describe('useNavItems rail', () => {
  beforeEach(() => {
    pluginList.length = 0
    runtimeFeatures.savedTasks = true
    runtimeFeatures.iamGroups = false
    runtimeFeatures.agentsEnabled = false
    runtimeFeatures.platformLinksEnabled = false
    runtimeFeatures.toolsApprovalsEnabled = false
    runtimeFeatures.desktopAgentEnabled = false
    resetAiAccountsGatewayCache()
    getMessagesGatewayStatus.mockReset()
    getMessagesGatewayStatus.mockResolvedValue({ enabled: false })
    Object.keys(runtimeModules).forEach((key) => {
      delete runtimeModules[key]
    })
  })

  it('guest rail has History only — no Manage, Plugins or Operate', () => {
    const wrapper = mountNav(null)
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toEqual(['chat'])
  })

  it('signed-in user has Sources + Manage groups, no Operate', () => {
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('files')
    expect(keys).toContain('manage')
    expect(keys).not.toContain('admin')
    expect(keys).not.toContain('channels')

    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect(hasNestedNavGroups(manage?.children)).toBe(true)
    const children = (manage?.children ?? []) as Array<{
      key: string
      path: string
      label: string
      groupKey?: string
    }>
    expect(children.map((child) => child.key)).toEqual([
      'saved-prompts',
      'ai-models',
      'saved-tasks',
      'apps',
      'apps-connected',
      'chat-widget',
    ])
    expect(groupNavChildren(children).map((group) => group.key)).toEqual([
      'assistants',
      'automations',
      'apps',
    ])
    expect(children.find((child) => child.key === 'saved-prompts')?.label).toBe('Shortcuts')
    expect(children.find((child) => child.key === 'ai-models')?.label).toBe('AI settings')
    expect(children.find((child) => child.key === 'saved-tasks')?.path).toBe('/tasks')
    expect(children.find((child) => child.key === 'apps')?.path).toBe('/apps')
    expect(children.find((child) => child.key === 'apps-connected')?.path).toBe('/apps/connected')
  })

  it('drops Tasks when Saved tasks is off and keeps the apps', () => {
    runtimeFeatures.savedTasks = false
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const childKeys = (manage?.children ?? []).map((child: { key: string }) => child.key)
    expect(childKeys).toContain('apps')
    expect(childKeys).not.toContain('saved-tasks')
    expect(
      new Set((manage?.children ?? []).map((child: { groupKey?: string }) => child.groupKey))
    ).toEqual(new Set(['assistants', 'apps']))
  })

  it('shows Approvals under Automations at /approvals when the flag is on', () => {
    runtimeFeatures.toolsApprovalsEnabled = true
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const child = (manage?.children ?? []).find((item: { key: string }) => item.key === 'approvals')
    expect(child?.label).toBe('Approvals')
    expect(child?.path).toBe('/approvals')
    expect(child?.groupKey).toBe('automations')
  })

  it('leads with Assistants when the flag is on', () => {
    runtimeFeatures.agentsEnabled = true
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const first = (manage?.children ?? [])[0] as { key: string; path: string } | undefined
    expect(first?.key).toBe('assistants')
    expect(first?.path).toBe('/ai/assistants')
  })

  it('lights up Connected, not All apps, on /apps/connected', () => {
    const all = { key: 'apps', path: '/apps', label: 'All apps' }
    const connected = { key: 'apps-connected', path: '/apps/connected', label: 'Connected' }
    expect(isNavChildActive(all, '/channels', '/apps/telegram')).toBe(true)
    expect(isNavChildActive(connected, '/channels', '/apps/telegram')).toBe(false)
    expect(isNavChildActive(connected, '/channels', '/apps/connected')).toBe(true)
  })

  it('admin also sees Operate', () => {
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('manage')
    expect(keys).toContain('admin')
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(operate?.label).toBe('Admin')
    const children = operate?.children ?? []
    const childKeys = children.map((child: { key: string }) => child.key)
    expect(childKeys).toContain('admin-people')
    expect(children.find((child: { key: string }) => child.key === 'admin-people')?.path).toBe(
      '/admin/people'
    )
  })

  it('orders Operate by topic and drops the separate Model status entry', () => {
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    const children = (operate?.children ?? []) as Array<{
      key: string
      label: string
      path: string
    }>

    expect(children.map((child) => child.key)).toEqual([
      'admin-dashboard',
      'admin-features',
      'admin-setup',
      'admin-people',
      'admin-partners',
      'admin-config',
    ])
    expect(children.find((child) => child.key === 'admin-features')?.label).toBe('System status')
    expect(children.some((child) => child.path === '/admin/model-status')).toBe(false)
  })

  it('shows models that need attention as a badge on AI infrastructure', async () => {
    setModelsNeedingAttention(3)
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    await flushPromises()
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    const setup = (operate?.children ?? []).find(
      (child: { key: string }) => child.key === 'admin-setup'
    )
    expect(setup?.badge).toBe('3')

    setModelsNeedingAttention(0)
    await flushPromises()
    const refreshed = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(
      (refreshed?.children ?? []).find((child: { key: string }) => child.key === 'admin-setup')
        ?.badge
    ).toBeUndefined()
  })

  it('People always points at /admin/people', () => {
    runtimeFeatures.iamGroups = false
    const off = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const offOperate = off.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(
      (offOperate?.children ?? []).find((child: { key: string }) => child.key === 'admin-people')
        ?.path
    ).toBe('/admin/people')

    runtimeFeatures.iamGroups = true
    const on = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const onOperate = on.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(
      (onOperate?.children ?? []).find((child: { key: string }) => child.key === 'admin-people')
        ?.path
    ).toBe('/admin/people')
  })

  it('plugins stay a top-level rail entry when installed', () => {
    pluginList.push({ name: 'fastbill' })
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('plugins')
    expect(keys.indexOf('plugins')).toBeLessThan(keys.indexOf('manage') + 10)
  })
})
