import { computed, ref, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ChatBubbleLeftRightIcon,
  FolderIcon,
  ShieldCheckIcon,
  SignalIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import {
  groupNavChildren,
  isNavChildActive,
  lastNavDestination,
  useNavItems,
  type NavChild,
  type NavChildGroup,
} from './useNavItems'
export type NavSectionKey = 'chats' | 'library' | 'assistants' | 'channels' | 'operate'

/** Incremented when the chats rail icon is clicked while chats is already open. */
export const chatsPanelRefresh = ref(0)

export interface NavSection {
  key: NavSectionKey
  label: string
  description: string
  icon: Component
  /** Stable rail test id (`btn-sidebar-v2-nav-*`). */
  testId: string
  requiresAuth: boolean
  gateFeature?: string
}

const ASSISTANT_GROUPS = new Set(['assistants', 'automations'])
const CHANNEL_GROUPS = new Set(['channels', 'connections', 'developer'])
const LAST_SECTION_KEY = 'synaplan.nav.lastSection'

const SECTION_KEYS: NavSectionKey[] = ['chats', 'library', 'assistants', 'channels', 'operate']

function readLastSection(): NavSectionKey {
  try {
    const stored = localStorage.getItem(LAST_SECTION_KEY)
    if (stored && SECTION_KEYS.includes(stored as NavSectionKey)) {
      return stored as NavSectionKey
    }
  } catch {
    // Private mode — the route still picks the section.
  }
  return 'chats'
}

/** Shared across rail and panel so a personal page keeps one section. */
const lastSection = ref<NavSectionKey>(readLastSection())

function longestMatch(
  children: NavChild[],
  sectionPath: string,
  routePath: string
): NavChild | null {
  const matches = children.filter((child) => isNavChildActive(child, sectionPath, routePath))
  matches.sort((a, b) => b.path.length - a.path.length)
  return matches[0] ?? null
}

/**
 * Desktop shell sections. Mobile keeps `useNavItems` unchanged.
 * The active section follows the route; personal pages keep the last one
 * so search and the profile stay in the panel.
 */
export function useNavSections() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const { navItems, isGuestMode, loadFeatureStatus } = useNavItems()

  const manage = computed(() => navItems.value.find((item) => item.key === 'manage'))
  const plugins = computed(() => navItems.value.find((item) => item.key === 'plugins'))
  const operateItem = computed(() => navItems.value.find((item) => item.key === 'admin'))

  const managePath = computed(() => manage.value?.path ?? '/channels')
  const operatePath = computed(() => operateItem.value?.path ?? '/admin')

  const manageGroups = computed(() => groupNavChildren(manage.value?.children))

  const assistantGroups = computed(() =>
    manageGroups.value.filter((group) => group.key !== null && ASSISTANT_GROUPS.has(group.key))
  )

  const channelGroups = computed<NavChildGroup[]>(() => {
    const groups = manageGroups.value.filter(
      (group) => group.key !== null && CHANNEL_GROUPS.has(group.key)
    )
    const pluginChildren = plugins.value?.children
    if (pluginChildren && pluginChildren.length > 0) {
      groups.push({
        key: 'plugins',
        group: t('nav.plugins'),
        items: pluginChildren,
      })
    }
    return groups
  })

  const operateGroups = computed(() => groupNavChildren(operateItem.value?.children))

  const sections = computed<NavSection[]>(() => {
    const chats: NavSection = {
      key: 'chats',
      label: t('nav.chats'),
      description: t('nav.historyDescription'),
      icon: ChatBubbleLeftRightIcon,
      testId: 'btn-sidebar-v2-nav-chat',
      requiresAuth: false,
    }
    if (isGuestMode.value) return [chats]

    const items: NavSection[] = [
      chats,
      {
        key: 'library',
        label: t('nav.files'),
        description: t('nav.filesDescription'),
        icon: FolderIcon,
        testId: 'btn-sidebar-v2-nav-files',
        requiresAuth: true,
        gateFeature: 'files',
      },
      {
        key: 'assistants',
        label: t('nav.assistants'),
        description: t('nav.assistantsDescription'),
        icon: SparklesIcon,
        testId: 'btn-sidebar-v2-nav-assistants',
        requiresAuth: true,
        gateFeature: 'channels',
      },
      {
        key: 'channels',
        label: t('nav.channels'),
        description: t('nav.channelsDescription'),
        icon: SignalIcon,
        testId: 'btn-sidebar-v2-nav-channels',
        requiresAuth: true,
        gateFeature: 'channels',
      },
    ]

    if (operateItem.value) {
      items.push({
        key: 'operate',
        label: t('nav.admin'),
        description: t('nav.operateDescription'),
        icon: ShieldCheckIcon,
        testId: 'btn-sidebar-v2-nav-admin',
        requiresAuth: true,
      })
    }

    return items
  })

  function sectionKeyForPath(path: string): NavSectionKey | null {
    if (
      path === '/' ||
      path === '/chats' ||
      path.startsWith('/chats/') ||
      path.startsWith('/chat/')
    ) {
      return 'chats'
    }
    if (path.startsWith('/files')) return 'library'
    if (path.startsWith('/admin') || path === '/setup') return 'operate'
    // Prefixes, not the live child list: a flag can point the link at
    // `/ai/instructions` while the route guard still serves `/ai/assistants`.
    if (
      path.startsWith('/ai/') ||
      path.startsWith('/channels/tasks') ||
      path.startsWith('/channels/approvals')
    ) {
      return 'assistants'
    }
    if (path.startsWith('/channels') || path.startsWith('/plugins') || path.startsWith('/tools')) {
      return 'channels'
    }

    const child = longestMatch(manage.value?.children ?? [], managePath.value, path)
    if (!child?.groupKey) return null
    if (ASSISTANT_GROUPS.has(child.groupKey)) return 'assistants'
    if (CHANNEL_GROUPS.has(child.groupKey)) return 'channels'
    return null
  }

  const allowedKeys = computed(() => new Set(sections.value.map((section) => section.key)))

  const activeKey = computed<NavSectionKey>(() => {
    const fromRoute = sectionKeyForPath(route.path)
    if (fromRoute && allowedKeys.value.has(fromRoute)) return fromRoute
    if (allowedKeys.value.has(lastSection.value)) return lastSection.value
    return 'chats'
  })

  watch(
    activeKey,
    (key) => {
      lastSection.value = key
      try {
        localStorage.setItem(LAST_SECTION_KEY, key)
      } catch {
        // Optional memory of the last section.
      }
    },
    { immediate: true }
  )

  const activeSection = computed(
    () => sections.value.find((section) => section.key === activeKey.value) ?? sections.value[0]
  )

  const activeGroups = computed<NavChildGroup[]>(() => {
    if (activeKey.value === 'assistants') return assistantGroups.value
    if (activeKey.value === 'channels') return channelGroups.value
    if (activeKey.value === 'operate') return operateGroups.value
    return []
  })

  const activeSectionPath = computed(() =>
    activeKey.value === 'operate' ? operatePath.value : managePath.value
  )

  function groupsFor(key: NavSectionKey): NavChildGroup[] {
    if (key === 'assistants') return assistantGroups.value
    if (key === 'channels') return channelGroups.value
    if (key === 'operate') return operateGroups.value
    return []
  }

  function homePath(key: NavSectionKey): string {
    if (key === 'chats') return '/'
    if (key === 'library') return '/files'
    if (key === 'operate') {
      const children = operateItem.value?.children ?? []
      const last = lastNavDestination('operate')
      if (last && longestMatch(children, operatePath.value, last)) return last
      return children[0]?.path ?? '/admin'
    }
    const children = groupsFor(key).flatMap((group) => group.items)
    const last = lastNavDestination('manage')
    if (last && longestMatch(children, managePath.value, last)) return last
    return children[0]?.path ?? (key === 'assistants' ? '/ai/models' : '/channels')
  }

  function selectSection(key: NavSectionKey) {
    // The chats panel stays mounted, so a second click on the active rail
    // icon never remounts it. Bump the refresh so a chat renamed elsewhere
    // (or created after the first load) shows up in the list.
    if (sectionKeyForPath(route.path) === key) {
      if (key === 'chats') chatsPanelRefresh.value += 1
      return
    }
    const target = homePath(key)
    if (route.path !== target) router.push(target)
  }

  return {
    sections,
    activeKey,
    activeSection,
    activeGroups,
    activeSectionPath,
    selectSection,
    isGuestMode,
    loadFeatureStatus,
    sectionKeyForPath,
  }
}
