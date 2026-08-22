import { computed, ref, type Component } from 'vue'
import { useRoute } from 'vue-router'
import {
  ClockIcon,
  CpuChipIcon,
  FolderIcon,
  PuzzlePieceIcon,
  ShieldCheckIcon,
  SignalIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useConfigStore } from '../stores/config'
import { getFeaturesStatus } from '../services/featuresService'
import { modelStatusApi } from '../services/api/adminModelStatusApi'
import { isSavedTasksEnabled } from './useSavedTasksFeature'

export interface NavChild {
  /** Stable identifier used for data-testid — never derived from the route path */
  key: string
  path: string
  label: string
  badge?: string
  group?: string
}

export interface NavChildGroup {
  group: string | null
  items: NavChild[]
}

/**
 * Splits a children list into consecutive sections by their `group` label so
 * both nav surfaces (desktop flyout, mobile drawer) render identical grouped
 * sub-menus. Ungrouped children form a header-less leading section.
 */
export function groupNavChildren(children: NavChild[] | undefined): NavChildGroup[] {
  if (!children || children.length === 0) return []
  if (!children.some((c) => c.group)) return [{ group: null, items: children }]

  const groups: NavChildGroup[] = []
  let currentGroup: string | null = null
  for (const child of children) {
    const group = child.group ?? null
    if (group !== currentGroup || groups.length === 0) {
      currentGroup = group
      groups.push({ group, items: [] })
    }
    groups[groups.length - 1].items.push(child)
  }
  return groups
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
 * Single source of truth for the primary navigation (§4.4 target structure).
 * Consumed by the desktop rail (SidebarV2) and the mobile bottom nav so the
 * two surfaces can never drift apart.
 */
export function useNavItems() {
  const { t } = useI18n()
  const route = useRoute()
  const authStore = useAuthStore()
  const configStore = useConfigStore()

  const isGuestMode = computed(() => !authStore.isAuthenticated)

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

    // §4.4: Search lives INSIDE the Files page (Browse | Search tabs since
    // phase 5) — the rail item is just "Files".
    items.push({
      key: 'files',
      path: '/files',
      label: t('nav.files'),
      description: t('nav.filesDescription'),
      icon: FolderIcon,
      requiresAuth: true,
      gateFeature: 'files',
    })

    // Channels + AI Setup are always present (Q6: easy mode shows them locked;
    // guests see them gate-locked). Canonical §4.6 URLs.
    //
    // Channels = where messages come in and go out. Grouped sub-menu:
    // Standard Inbound, Your Widgets, Connections (Configure + MCP), API.
    const channelsChildren: NavChild[] = [
      { key: 'inbound', path: '/channels', label: t('nav.configInbound') },
      { key: 'chat-widget', path: '/channels/widgets', label: t('nav.toolsChatWidget') },
      ...(isSavedTasksEnabled()
        ? [
            {
              key: 'connections',
              path: '/channels/connections',
              label: t('nav.configConnections'),
              group: t('nav.connections'),
            },
          ]
        : []),
      {
        key: 'mcp-servers',
        path: '/channels/mcp',
        label: t('nav.mcpServers'),
        group: t('nav.connections'),
      },
      {
        key: 'api-keys',
        path: '/channels/api',
        label: t('nav.configApiKeys'),
        group: t('nav.groupApi'),
      },
      {
        key: 'api-docs',
        path: '/channels/api/docs',
        label: t('pageTitles.configApiDocs'),
        group: t('nav.groupApi'),
      },
    ]

    items.push({
      key: 'channels',
      path: '/channels',
      label: t('nav.channels'),
      description: t('nav.channelsDescription'),
      icon: SignalIcon,
      requiresAuth: true,
      gateFeature: 'channels',
      children: isGuestMode.value ? undefined : channelsChildren,
    })

    // AI Setup & Tools = how the AI behaves plus the automation tools that
    // run on top of it (recurring tasks, agents, summarizer, email handler).
    const aiSetupChildren: NavChild[] = [
      { key: 'ai-models', path: '/ai/models', label: t('nav.configAiModels') },
      { key: 'task-prompts', path: '/ai/instructions', label: t('nav.configTaskPrompts') },
      { key: 'sorting-prompt', path: '/ai/routing', label: t('nav.configSortingPrompt') },
      ...(isSavedTasksEnabled()
        ? [{ key: 'saved-tasks', path: '/channels/tasks', label: t('nav.savedTasks') }]
        : []),
      { key: 'ai-agents', path: '/channels/agents', label: t('nav.aiAgents') },
      // Transitional home (Q3): retires into the in-chat Tools dropdown later.
      { key: 'doc-summary', path: '/ai/summarizer', label: t('nav.toolsDocSummary') },
      { key: 'mail-handler', path: '/channels/email', label: t('nav.toolsMailHandler') },
    ]

    items.push({
      key: 'ai-setup',
      path: '/ai/models',
      label: t('nav.aiSetup'),
      description: t('nav.aiSetupDescription'),
      icon: CpuChipIcon,
      requiresAuth: true,
      gateFeature: 'aiSetup',
      children: isGuestMode.value ? undefined : aiSetupChildren,
    })

    if (configStore.plugins.length > 0) {
      items.push({
        key: 'plugins',
        path: '/plugins',
        label: t('nav.plugins'),
        icon: PuzzlePieceIcon,
        requiresAuth: true,
        children: isGuestMode.value
          ? undefined
          : configStore.plugins.map((plugin: { name?: string }) => ({
              key: `plugin-${plugin.name ?? 'unknown'}`,
              path: `/plugins/${plugin.name}`,
              label: plugin.name
                ? plugin.name.charAt(0).toUpperCase() + plugin.name.slice(1)
                : t('common.unknown'),
            })),
      })
    }

    if (authStore.isAdmin) {
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
      // The section-overview child shares the section's own path (e.g. the
      // Channels "Standard Inbound" child lives at /channels). It must match
      // exactly, otherwise it would claim every /channels/* page — including
      // the ones that now belong to AI Setup & Tools (tasks, agents, email).
      return item.children.some((child) =>
        child.path === item.path ? route.path === child.path : route.path.startsWith(child.path)
      )
    }
    return route.path.startsWith(item.path)
  }

  return { navItems, isItemActive, isGuestMode, loadFeatureStatus }
}
