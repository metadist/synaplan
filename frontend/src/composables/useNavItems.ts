import { computed, ref, watch, type Component } from 'vue'
import { useRoute } from 'vue-router'
import {
  ClockIcon,
  FolderIcon,
  PuzzlePieceIcon,
  ShieldCheckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useConfigStore } from '../stores/config'
import { getFeaturesStatus } from '../services/featuresService'
import { modelStatusApi } from '../services/api/adminModelStatusApi'
import { isSavedTasksEnabled } from './useSavedTasksFeature'
import { isDesktopAgentEnabled } from './useDesktopAgentFeature'

export interface NavChild {
  /** Stable identifier used for data-testid — never derived from the route path */
  key: string
  path: string
  label: string
  badge?: string
  /** Translated group label shown in the first-level Manage flyout */
  group?: string
  /** Stable group id (`assistants`, `channels`, …) for testids and nesting */
  groupKey?: string
}

export interface NavChildGroup {
  /** Stable group id, or null for a flat (ungrouped) list */
  key: string | null
  group: string | null
  items: NavChild[]
}

/**
 * Splits a children list into consecutive sections by their `groupKey` (falling
 * back to the translated `group` label) so both nav surfaces (desktop flyout,
 * mobile drawer) render the same hierarchy. Ungrouped children form a
 * header-less leading section.
 */
export function groupNavChildren(children: NavChild[] | undefined): NavChildGroup[] {
  if (!children || children.length === 0) return []
  if (!children.some((c) => c.group || c.groupKey)) {
    return [{ key: null, group: null, items: children }]
  }

  const groups: NavChildGroup[] = []
  let currentKey: string | null = null
  for (const child of children) {
    const key = child.groupKey ?? child.group ?? null
    if (key !== currentKey || groups.length === 0) {
      currentKey = key
      groups.push({ key: child.groupKey ?? null, group: child.group ?? null, items: [] })
    }
    groups[groups.length - 1].items.push(child)
  }
  return groups
}

/** True when children should render as a two-level flyout / accordion. */
export function hasNestedNavGroups(children: NavChild[] | undefined): boolean {
  return !!children?.some((child) => !!child.groupKey)
}

/**
 * Section-overview children share the parent's path (Inbound is `/channels`).
 * Match those exactly so `/channels/widgets` does not light up Inbound;
 * every other child uses prefix match.
 */
export function isNavChildActive(child: NavChild, sectionPath: string, routePath: string): boolean {
  return child.path === sectionPath ? routePath === child.path : routePath.startsWith(child.path)
}

export interface NavItem {
  /** Stable identifier used for data-testid — never derived from the route path */
  key: string
  path: string
  label: string
  /** Longer description for title/aria — the visible label stays short */
  description?: string
  icon: Component
  isUpgrade?: boolean
  requiresAuth?: boolean
  gateFeature?: string
  children?: NavChild[]
}

// Module-scoped so the desktop rail and the mobile nav share one fetch.
const disabledFeaturesCount = ref(0)
const offlineModelsCount = ref(0)
let featureStatusRequested = false

/**
 * Workspace seam — one named predicate per nav context
 * (20260828-interface-streamlining-sprint / contract §6).
 * When a real workspace capability exists later, only `canSeeManage` changes.
 */
export const canSeeWork = (): boolean => true
export const canSeeManage = (signedIn: boolean): boolean => signedIn
export const canSeeOperate = (isAdmin: boolean): boolean => isAdmin

const LAST_MANAGE_KEY = 'synaplan.nav.lastManage'
const LAST_OPERATE_KEY = 'synaplan.nav.lastOperate'

export function rememberNavDestination(context: 'manage' | 'operate', path: string): void {
  try {
    localStorage.setItem(context === 'manage' ? LAST_MANAGE_KEY : LAST_OPERATE_KEY, path)
  } catch {
    // Private mode / storage blocked — last destination is optional.
  }
}

export function lastNavDestination(context: 'manage' | 'operate'): string | null {
  try {
    return localStorage.getItem(context === 'manage' ? LAST_MANAGE_KEY : LAST_OPERATE_KEY)
  } catch {
    return null
  }
}

/**
 * Single source of truth for the primary navigation.
 * Consumed by the desktop rail (SidebarV2) and the mobile drawer so the
 * two surfaces can never drift apart.
 */
export function useNavItems() {
  const { t } = useI18n()
  const route = useRoute()
  const authStore = useAuthStore()
  const configStore = useConfigStore()

  const isGuestMode = computed(() => !authStore.isAuthenticated)
  const signedIn = computed(() => authStore.isAuthenticated)

  const loadFeatureStatus = async () => {
    try {
      if (!authStore.isAdmin || !authStore.isAuthenticated) return
      if (featureStatusRequested) return
      featureStatusRequested = true

      const status = await getFeaturesStatus()
      if (status && status.features) {
        disabledFeaturesCount.value = Object.values(status.features).filter(
          (f) => !f.enabled
        ).length
      } else {
        disabledFeaturesCount.value = 0
      }
    } catch {
      disabledFeaturesCount.value = 0
    }

    // Both admin badges load together: they share the same trigger, the same
    // admin guard and the same once-per-session flag, and splitting them would
    // mean two entry points for callers to remember.
    try {
      const status = await modelStatusApi.getStatus()
      offlineModelsCount.value = status.summary.needsAttention
    } catch {
      offlineModelsCount.value = 0
    }
  }

  const navItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
      {
        key: 'chat',
        path: '/',
        label: t('nav.history'),
        description: t('nav.historyDescription'),
        icon: ClockIcon,
      },
    ]

    // Guests: Chat + Sign in only. Sources is everyday work for signed-in users.
    if (canSeeManage(signedIn.value)) {
      items.push({
        key: 'files',
        path: '/files',
        label: t('nav.files'),
        description: t('nav.filesDescription'),
        icon: FolderIcon,
        requiresAuth: true,
        gateFeature: 'files',
      })
    }

    if (canSeeManage(signedIn.value)) {
      const assistants = t('nav.groupAssistants')
      const channels = t('nav.channels')
      const automations = t('nav.groupAutomations')
      const tools = t('nav.groupTools')
      const connections = t('nav.connections')
      const api = t('nav.groupApi')

      const grouped = (groupKey: string, group: string) => ({ groupKey, group })

      const manageChildren: NavChild[] = [
        {
          key: 'ai-models',
          path: '/ai/models',
          label: t('nav.configAiModels'),
          ...grouped('assistants', assistants),
        },
        {
          key: 'task-prompts',
          path: '/ai/instructions',
          label: t('nav.configTaskPrompts'),
          ...grouped('assistants', assistants),
        },
        {
          key: 'sorting-prompt',
          path: '/ai/routing',
          label: t('nav.configSortingPrompt'),
          ...grouped('assistants', assistants),
        },
        {
          key: 'inbound',
          path: '/channels',
          label: t('nav.configInbound'),
          ...grouped('channels', channels),
        },
        {
          key: 'chat-widget',
          path: '/channels/widgets',
          label: t('nav.toolsChatWidget'),
          ...grouped('channels', channels),
        },
        {
          key: 'mail-handler',
          path: '/channels/email',
          label: t('nav.toolsMailHandler'),
          ...grouped('channels', channels),
        },
        {
          key: 'live-support',
          path: '/channels/widgets/live-support',
          label: t('nav.liveSupport'),
          ...grouped('channels', channels),
        },
        ...(isSavedTasksEnabled()
          ? [
              {
                key: 'connections',
                path: '/channels/connections',
                label: t('nav.configConnections'),
                ...grouped('connections', connections),
              },
            ]
          : []),
        {
          key: 'mcp-servers',
          path: '/channels/mcp',
          label: t('nav.mcpServers'),
          ...grouped('connections', connections),
        },
        ...(isDesktopAgentEnabled()
          ? [
              {
                key: 'desktop',
                path: '/channels/desktop',
                label: t('nav.desktop'),
                ...grouped('connections', connections),
              },
            ]
          : []),
        {
          key: 'api-keys',
          path: '/channels/api',
          label: t('nav.configApiKeys'),
          ...grouped('api', api),
        },
        {
          key: 'api-docs',
          path: '/channels/api/docs',
          label: t('pageTitles.configApiDocs'),
          ...grouped('api', api),
        },
        ...(isSavedTasksEnabled()
          ? [
              {
                key: 'saved-tasks',
                path: '/channels/tasks',
                label: t('nav.savedTasks'),
                ...grouped('automations', automations),
              },
            ]
          : []),
        {
          key: 'ai-agents',
          path: '/channels/agents',
          label: t('nav.aiAgents'),
          ...grouped('automations', automations),
        },
        {
          key: 'doc-summary',
          path: '/ai/summarizer',
          label: t('nav.toolsDocSummary'),
          ...grouped('tools', tools),
        },
      ]

      items.push({
        key: 'manage',
        path: '/channels',
        label: t('nav.manage'),
        description: t('nav.manageDescription'),
        icon: WrenchScrewdriverIcon,
        requiresAuth: true,
        gateFeature: 'channels',
        children: manageChildren,
      })
    }

    if (signedIn.value && configStore.plugins.length > 0) {
      items.push({
        key: 'plugins',
        path: '/plugins',
        label: t('nav.plugins'),
        icon: PuzzlePieceIcon,
        requiresAuth: true,
        children: configStore.plugins.map((plugin: { name?: string }) => ({
          key: `plugin-${plugin.name ?? 'unknown'}`,
          path: `/plugins/${plugin.name}`,
          label: plugin.name
            ? plugin.name.charAt(0).toUpperCase() + plugin.name.slice(1)
            : t('common.unknown'),
        })),
      })
    }

    if (canSeeOperate(authStore.isAdmin)) {
      const adminChildren: NavChild[] = [
        { key: 'admin-dashboard', path: '/admin', label: t('nav.adminDashboard') },
      ]

      const featureStatusItem: NavChild = {
        key: 'admin-features',
        path: '/admin/features',
        label: t('nav.adminFeatureStatus'),
      }
      if (disabledFeaturesCount.value > 0) {
        featureStatusItem.badge = String(disabledFeaturesCount.value)
      }
      adminChildren.push(featureStatusItem)

      const modelStatusItem: NavChild = {
        key: 'admin-model-status',
        path: '/admin/model-status',
        label: t('nav.adminModelStatus'),
      }
      if (offlineModelsCount.value > 0) {
        modelStatusItem.badge = String(offlineModelsCount.value)
      }
      adminChildren.push(modelStatusItem)

      adminChildren.push({
        key: 'admin-setup',
        path: '/admin/setup',
        label: t('nav.adminProviderSetup'),
      })
      adminChildren.push({
        key: 'admin-config',
        path: '/admin/config',
        label: t('nav.adminSystemConfig'),
      })

      items.push({
        key: 'admin',
        path: '/admin',
        label: t('nav.admin'),
        icon: ShieldCheckIcon,
        children: adminChildren,
      })
    }

    return items
  })

  const isItemActive = (item: NavItem): boolean => {
    if (item.path === '/') {
      return route.path === '/' || route.path.startsWith('/chat')
    }
    if (item.key === 'files') {
      // /files and /files/search are tabs of the same surface.
      return route.path.startsWith('/files')
    }
    if (item.children && item.children.length > 0) {
      return item.children.some((child) => isNavChildActive(child, item.path, route.path))
    }
    return route.path.startsWith(item.path)
  }

  watch(
    () => route.path,
    (path) => {
      const manage = navItems.value.find((item) => item.key === 'manage')
      if (manage?.children?.some((child) => isNavChildActive(child, manage.path, path))) {
        rememberNavDestination('manage', path)
      }
      const operate = navItems.value.find((item) => item.key === 'admin')
      if (
        operate?.children?.some((child) => path === child.path || path.startsWith(`${child.path}/`))
      ) {
        rememberNavDestination('operate', path)
      }
    }
  )

  return { navItems, isItemActive, isGuestMode, loadFeatureStatus, signedIn }
}
