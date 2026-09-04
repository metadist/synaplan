import { defineComponent } from 'vue'
import { describe, expect, it, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
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

const runtimeFeatures = { savedTasks: true, iamGroups: false }

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
  getApiBaseUrl: () => 'http://localhost:8000',
  getConfigSync: () => ({ features: runtimeFeatures }),
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
    groupApi: 'API',
    configInbound: 'Inbound',
    toolsChatWidget: 'Chat widgets',
    toolsMailHandler: 'Email handler',
    configConnections: 'Connections',
    mcpServers: 'MCP Servers',
    configApiKeys: 'API Keys',
    savedTasks: 'Saved tasks',
    aiAgents: 'AI Agents',
    toolsDocSummary: 'Summarizer',
    configAiModels: 'Models',
    configTaskPrompts: 'Instructions',
    configSortingPrompt: 'Routing',
    liveSupport: 'Live support',
    plugins: 'Plugins',
    admin: 'Operate',
    adminDashboard: 'Overview',
    adminFeatureStatus: 'Feature Status',
    adminModelStatus: 'Model Status',
    adminProviderSetup: 'AI providers',
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
    expect(childKeys).toContain('live-support')
    expect(childKeys).toContain('chat-widget')
    expect(
      new Set((manage?.children ?? []).map((child: { groupKey?: string }) => child.groupKey))
    ).toEqual(new Set(['assistants', 'channels', 'connections', 'api', 'automations', 'tools']))
  })

  it('admin also sees Operate', () => {
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('manage')
    expect(keys).toContain('admin')
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    expect(operate?.label).toBe('Operate')
    const childKeys = (operate?.children ?? []).map((child: { key: string }) => child.key)
    expect(childKeys).not.toContain('admin-people')
  })

  it('shows People under Operate only when IAM groups are enabled', () => {
    runtimeFeatures.iamGroups = true
    const wrapper = mountNav({ email: 'admin@test.com', level: 'ADMIN', isAdmin: true })
    const operate = wrapper.vm.navItems.find((item: { key: string }) => item.key === 'admin')
    const childKeys = (operate?.children ?? []).map((child: { key: string }) => child.key)
    expect(childKeys).toContain('admin-people')
  })

  it('plugins stay a top-level rail entry when installed', () => {
    pluginList.push({ name: 'fastbill' })
    const wrapper = mountNav({ email: 'user@test.com', level: 'PRO' })
    const keys = wrapper.vm.navItems.map((item: { key: string }) => item.key)
    expect(keys).toContain('plugins')
    expect(keys.indexOf('plugins')).toBeLessThan(keys.indexOf('manage') + 10)
  })
})
