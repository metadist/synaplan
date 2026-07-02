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
import { useAppModeStore } from '../stores/appMode'
import { useAuthStore } from '../stores/auth'
import { useConfigStore } from '../stores/config'
import { getFeaturesStatus } from '../services/featuresService'

export interface NavChild {
  /** Stable identifier used for data-testid — never derived from the route path */
  key: string
  path: string
  label: string
  badge?: string
  group?: string
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
  /**
   * Advanced-only items: hidden entirely while the app is in easy mode
   * (signed-in users). Guests still see them gate-locked, since the guest
   * lock is a conversion affordance, not an app-mode one.
   */
  lockedInEasyMode?: boolean
  children?: NavChild[]
}

// Module-scoped so the desktop rail and the mobile nav share one fetch.
const disabledFeaturesCount = ref(0)
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
  const appModeStore = useAppModeStore()
  const configStore = useConfigStore()

  const isGuestMode = computed(() => !authStore.isAuthenticated)

  const loadFeatureStatus = async () => {
    try {
      if (!import.meta.env.DEV) return
      if (!authStore.user || !authStore.isAuthenticated) return
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
    const channelsChildren: NavChild[] = [
      { key: 'inbound', path: '/channels', label: t('nav.configInbound') },
      { key: 'chat-widget', path: '/channels/widgets', label: t('nav.toolsChatWidget') },
      { key: 'mail-handler', path: '/channels/email', label: t('nav.toolsMailHandler') },
      { key: 'api-keys', path: '/channels/api', label: t('nav.configApiKeys') },
      { key: 'api-docs', path: '/channels/api/docs', label: t('pageTitles.configApiDocs') },
    ]

    items.push({
      key: 'channels',
      path: '/channels',
      label: t('nav.channels'),
      description: t('nav.channelsDescription'),
      icon: SignalIcon,
      requiresAuth: true,
      gateFeature: 'settings',
      lockedInEasyMode: true,
      children: isGuestMode.value ? undefined : channelsChildren,
    })

    const aiSetupChildren: NavChild[] = [
      { key: 'ai-models', path: '/ai/models', label: t('nav.configAiModels') },
      { key: 'task-prompts', path: '/ai/instructions', label: t('nav.configTaskPrompts') },
      { key: 'sorting-prompt', path: '/ai/routing', label: t('nav.configSortingPrompt') },
      // Transitional home (Q3): retires into the in-chat Tools dropdown later.
      { key: 'doc-summary', path: '/ai/summarizer', label: t('nav.toolsDocSummary') },
    ]

    items.push({
      key: 'ai-setup',
      path: '/ai/models',
      label: t('nav.aiSetup'),
      description: t('nav.aiSetupDescription'),
      icon: CpuChipIcon,
      requiresAuth: true,
      gateFeature: 'settings',
      lockedInEasyMode: true,
      children: isGuestMode.value ? undefined : aiSetupChildren,
    })

    if (configStore.plugins.length > 0) {
      items.push({
        key: 'plugins',
        path: '/plugins',
        label: t('nav.plugins'),
        icon: PuzzlePieceIcon,
        requiresAuth: true,
        lockedInEasyMode: true,
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

      if (import.meta.env.DEV) {
        const featureStatusItem: NavChild = {
          key: 'admin-features',
          path: '/admin/features',
          label: t('nav.adminFeatureStatus'),
        }
        if (disabledFeaturesCount.value > 0) {
          featureStatusItem.badge = String(disabledFeaturesCount.value)
        }
        adminChildren.push(featureStatusItem)
      }
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

    // Easy mode hides advanced-only items instead of showing them locked.
    // Guests keep seeing them (gate-locked) as a signup affordance.
    if (appModeStore.isEasyMode && !isGuestMode.value) {
      return items.filter((item) => !item.lockedInEasyMode)
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
      return item.children.some((child) => route.path.startsWith(child.path))
    }
    return route.path.startsWith(item.path)
  }

  /**
   * Easy-mode lock state. Since easy mode now filters advanced-only items
   * out of `navItems` for signed-in users, this is false for every rendered
   * item — kept so SidebarV2/MobileNav keep working with routes reached
   * directly (e.g. a bookmarked /channels URL while in easy mode).
   */
  const isItemLocked = (item: NavItem): boolean =>
    Boolean(item.lockedInEasyMode) && appModeStore.isEasyMode && !isGuestMode.value

  return { navItems, isItemActive, isItemLocked, isGuestMode, loadFeatureStatus }
}
