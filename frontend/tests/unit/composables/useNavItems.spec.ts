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
  useNavItems,
} from '@/composables/useNavItems'
import { useAuthStore, type User } from '@/stores/auth'
import { loadGatewayEnabled, resetAiAccountsGatewayCache } from '@/composables/useAiAccounts'

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
    groupDeveloper: 'Developer & devices',
    desktop: 'Desktop',
    groupApi: 'API',
    myGroups: 'My groups',
    configInbound: 'Inbound',
    toolsChatWidget: 'Chat widgets',
    toolsMailHandler: 'Email handler',
    configConnections: 'Connected apps',
    mcpServers: 'MCP Servers',
    configApiKeys: 'API Keys',
    savedTasks: 'Saved tasks',
    approvals: 'Approvals',
    aiAgents: 'Coding clients',
    assistants: 'Assistants',
    linkedPlatforms: 'Linked platforms',
    toolsDocSummary: 'Summarizer',
    configAiModels: 'Models',
    aiAccounts: 'Your AI accounts',
    configTaskPrompts: 'Instructions',
    configSortingPrompt: 'Routing',
    liveSupport: 'Live support',
    plugins: 'Plugins',
    admin: 'Operate',
    adminDashboard: 'Overview',
    adminFeatureStatus: 'Feature Status',
    adminModelStatus: 'Model Status',
    adminProviderSetup: 'AI infrastructure',
    adminSystemConfig: 'System configuration',
    adminPeople: 'People',
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
    expect(keys).not.toContain('ai-setup')

    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect(manage?.children).toBeDefined()
    expect(hasNestedNavGroups(manage?.children)).toBe(true)
    const childKeys = (manage?.children ?? []).map((child: { key: string }) => child.key)
    expect(childKeys).toContain('mail-handler')
    expect(childKeys).toContain('saved-tasks')
    expect(childKeys).not.toContain('approvals')
    expect(childKeys).toContain('live-support')
    expect(childKeys).toContain('chat-widget')
    expect(childKeys).toContain('doc-summary')
    expect(childKeys).toContain('ai-accounts')
    expect(childKeys).toContain('api-docs')
    expect(childKeys).toContain('api-keys')
    expect(childKeys).toContain('ai-agents')
    expect(childKeys).toContain('connections')
    expect(childKeys).not.toContain('linked-platforms')
    expect(childKeys).not.toContain('desktop')
    const promptChild = (manage?.children ?? []).find(
      (child: { key: string }) => child.key === 'task-prompts'
    )
    expect(promptChild?.label).toBe('Instructions')
    expect(promptChild?.path).toBe('/ai/instructions')
    const manageGroups = groupNavChildren(manage?.children ?? [])
    expect(manageGroups.map((group: { key: string | null }) => group.key)).toEqual([
      'assistants',
      'automations',
      'channels',
      'connections',
      'developer',
    ])
    const connectionsChild = (manage?.children ?? []).find(
      (child: { key: string }) => child.key === 'connections'
    )
    expect(connectionsChild?.label).toBe('Connected apps')
    expect(connectionsChild?.groupKey).toBe('connections')
    const codingClients = (manage?.children ?? []).find(
      (child: { key: string }) => child.key === 'ai-agents'
    )
    expect(codingClients?.groupKey).toBe('developer')
    expect(
      (manage?.children ?? [])
        .filter((child: { groupKey?: string }) => child.groupKey === 'developer')
        .map((child: { key: string }) => child.key)
    ).toEqual(['api-keys', 'api-docs', 'ai-agents'])
  })

  it('keeps Connected apps visible when Saved tasks is off', () => {
    runtimeFeatures.savedTasks = false
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const childKeys = (manage?.children ?? []).map((child: { key: string }) => child.key)
    expect(childKeys).toContain('connections')
    expect(childKeys).not.toContain('saved-tasks')
    expect(
      new Set((manage?.children ?? []).map((child: { groupKey?: string }) => child.groupKey))
    ).toEqual(new Set(['assistants', 'channels', 'connections', 'developer']))
  })

  it('shows Desktop under Developer & devices only when the flag is on', () => {
    const off = mountNav({ email: 'user@test.com', level: 'PRO' })
    const offManage = off.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect((offManage?.children ?? []).map((child: { key: string }) => child.key)).not.toContain(
      'desktop'
    )

    runtimeFeatures.desktopAgentEnabled = true
    const on = mountNav({ email: 'user@test.com', level: 'PRO' })
    const onManage = on.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const desktop = (onManage?.children ?? []).find(
      (child: { key: string }) => child.key === 'desktop'
    )
    expect(desktop?.groupKey).toBe('developer')
    expect(desktop?.path).toBe('/channels/desktop')
  })

  it('shows Approvals under Automations when the flag is on', () => {
    runtimeFeatures.toolsApprovalsEnabled = true
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const child = (manage?.children ?? []).find((item: { key: string }) => item.key === 'approvals')
    expect(child?.label).toBe('Approvals')
    expect(child?.path).toBe('/channels/approvals')
    expect(child?.groupKey).toBe('automations')
  })

  it('hides Linked platforms when the flag is off and shows it when on', () => {
    const off = mountNav({ email: 'user@test.com', level: 'PRO' })
    const offManage = off.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect((offManage?.children ?? []).map((child: { key: string }) => child.key)).not.toContain(
      'linked-platforms'
    )

    runtimeFeatures.platformLinksEnabled = true
    const on = mountNav({ email: 'user@test.com', level: 'PRO' })
    const onManage = on.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect((onManage?.children ?? []).map((child: { key: string }) => child.key)).toContain(
      'linked-platforms'
    )
  })

  it('hides Your AI accounts when Higgsfield and the gateway are both off', () => {
    runtimeModules.higgsfield = { configured: false }
    getMessagesGatewayStatus.mockResolvedValue({ enabled: false })
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect((manage?.children ?? []).map((child: { key: string }) => child.key)).not.toContain(
      'ai-accounts'
    )
  })

  it('shows Your AI accounts when only the gateway is enabled', async () => {
    runtimeModules.higgsfield = { configured: false }
    getMessagesGatewayStatus.mockResolvedValue({ enabled: true })
    resetAiAccountsGatewayCache()
    await loadGatewayEnabled()
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    await flushPromises()
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    expect((manage?.children ?? []).map((child: { key: string }) => child.key)).toContain(
      'ai-accounts'
    )
  })

  it('replaces Instructions with Assistants when the flag is on', () => {
    runtimeFeatures.agentsEnabled = true
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const manage = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'manage')
    const promptChild = (manage?.children ?? []).find(
      (child: { key: string }) => child.key === 'task-prompts'
    )
    expect(promptChild?.label).toBe('Assistants')
    expect(promptChild?.path).toBe('/ai/assistants')
  })

  it('admin also sees Operate', () => {
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('manage')
    expect(keys).toContain('admin')
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(operate?.label).toBe('Operate')
    const children = operate?.children ?? []
    const childKeys = children.map((child: { key: string }) => child.key)
    expect(childKeys).toContain('admin-people')
    expect(children.find((child: { key: string }) => child.key === 'admin-people')?.path).toBe(
      '/admin/people'
    )
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
