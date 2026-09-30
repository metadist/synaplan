import { computed, type Component } from 'vue'
import { useRouter } from 'vue-router'
import {
  ChartBarIcon,
  ChatBubbleLeftRightIcon,
  Cog6ToothIcon,
  DocumentMagnifyingGlassIcon,
  InboxArrowDownIcon,
  UserCircleIcon,
  UserGroupIcon,
  CircleStackIcon,
} from '@heroicons/vue/24/outline'
import { i18n } from '@/i18n/instance'
import { allLocaleTexts } from './localeTexts'
import { useNavItems } from '@/composables/useNavItems'
import { useConfigStore } from '@/stores/config'
import { isIamGroupsEnabled, isIamSharingEnabled } from '@/composables/useIamFeature'
import type { LocalSearchDoc, SearchResult } from './types'

export interface LocalEntry {
  result: SearchResult
  doc: LocalSearchDoc
}

interface Destination {
  path: string
  label: string
  breadcrumb: string
  icon: Component
  /** i18n key of extra search words (all locales) for a section inside a page. */
  synonymsKey?: string
}

const AI_SETUP_PATH = '/admin/setup'

/**
 * Destinations the palette can open. Primary navigation comes from
 * `useNavItems()` (flag- and role-filtered, the single source of truth);
 * account destinations mirror the account menu's own conditions.
 */
export function usePageSources() {
  const router = useRouter()
  const configStore = useConfigStore()
  const { navItems, signedIn } = useNavItems()
  const t = i18n.global.t

  const destinations = computed<Destination[]>(() => {
    const list: Destination[] = []
    for (const item of navItems.value) {
      if (!item.children || item.children.length === 0) {
        list.push({ path: item.path, label: item.label, breadcrumb: '', icon: item.icon })
        continue
      }
      for (const child of item.children) {
        const breadcrumb = child.group ? `${item.label} › ${child.group}` : item.label
        list.push({ path: child.path, label: child.label, breadcrumb, icon: item.icon })
        if (child.path === AI_SETUP_PATH) {
          list.push({
            path: `${AI_SETUP_PATH}?tab=search`,
            label: String(t('aiInfra.searchModels.title')),
            breadcrumb: `${breadcrumb} › ${child.label} › ${String(t('adminSetup.tabs.search'))}`,
            icon: item.icon,
            synonymsKey: 'search.palette.synonyms.search_models',
          })
        }
      }
    }

    if (!signedIn.value) return list

    const account = String(t('search.palette.breadcrumb.account'))
    const files = String(t('nav.files'))
    list.push(
      {
        path: '/profile',
        label: String(t('nav.profile')),
        breadcrumb: account,
        icon: UserCircleIcon,
      },
      {
        path: '/settings',
        label: String(t('nav.preferences')),
        breadcrumb: account,
        icon: Cog6ToothIcon,
      },
      {
        path: '/statistics',
        label: String(t('nav.statistics')),
        breadcrumb: account,
        icon: ChartBarIcon,
      },
      {
        path: '/chats',
        label: String(t('pageTitles.allChats')),
        breadcrumb: account,
        icon: ChatBubbleLeftRightIcon,
      },
      {
        path: '/files/search',
        label: String(t('pageTitles.ragSearch')),
        breadcrumb: files,
        icon: DocumentMagnifyingGlassIcon,
      },
      {
        path: '/files/vectors',
        label: String(t('pageTitles.vectorStorage')),
        breadcrumb: files,
        icon: CircleStackIcon,
      }
    )
    if (configStore.features?.memoryService) {
      list.push({
        path: '/memories',
        label: String(t('pageTitles.memories')),
        breadcrumb: account,
        icon: CircleStackIcon,
      })
    }
    if (isIamSharingEnabled()) {
      list.push({
        path: '/chats/incoming',
        label: String(t('pageTitles.incoming')),
        breadcrumb: account,
        icon: InboxArrowDownIcon,
      })
    }
    if (isIamGroupsEnabled()) {
      list.push({
        path: '/groups',
        label: String(t('nav.myGroups')),
        breadcrumb: account,
        icon: UserGroupIcon,
      })
    }
    return list
  })

  const entries = computed<LocalEntry[]>(() =>
    destinations.value.map((dest) => {
      const resolved = router.resolve(dest.path)
      const titleKey = typeof resolved.meta.titleKey === 'string' ? resolved.meta.titleKey : null
      const routeName = typeof resolved.name === 'string' ? resolved.name : ''
      const keywords = [
        ...(titleKey ? allLocaleTexts(titleKey) : []),
        ...(routeName
          ? allLocaleTexts(`search.palette.synonyms.${routeName.replace(/-/g, '_')}`)
          : []),
        ...(dest.synonymsKey ? allLocaleTexts(dest.synonymsKey) : []),
        dest.path.replace(/[/-]/g, ' '),
      ]
      const id = `page:${dest.path}`
      return {
        result: {
          id,
          kind: 'page',
          title: dest.label,
          subtitle: dest.breadcrumb || undefined,
          icon: dest.icon,
          matchedBy: 'local',
          route: dest.path,
        },
        doc: { id, title: dest.label, keywords: keywords.join(' '), subtitle: dest.breadcrumb },
      }
    })
  )

  return { entries }
}
