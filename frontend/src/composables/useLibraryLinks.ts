import { computed, onMounted, ref, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  CircleStackIcon,
  FolderIcon,
  FolderPlusIcon,
  InboxArrowDownIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { getConfigSync } from '@/services/api/httpClient'
import filesService from '@/services/filesService'

export interface LibraryLink {
  id: 'browse' | 'incoming' | 'generated' | 'workspace' | 'search' | 'vectors'
  to: string
  label: string
  icon: Component
  sidebarTestId: string
  mobileTestId: string
  badge?: number
}

const incomingCount = ref(0)
let incomingRequested = false

async function loadIncomingCount(): Promise<void> {
  if (incomingRequested) return
  incomingRequested = true
  try {
    const facets = await filesService.getFacets()
    incomingCount.value = facets.incoming
  } catch {
    incomingCount.value = 0
  }
}

/** Browse is the section root, so only an exact match lights it up. */
export function isLibraryLinkActive(path: string, routePath: string): boolean {
  return path === '/files' ? routePath === '/files' : routePath.startsWith(path)
}

/**
 * Library destinations shared by the desktop sidebar and the phone drawer.
 * The page itself no longer repeats this list.
 */
export function useLibraryLinks() {
  const { t } = useI18n()
  const authStore = useAuthStore()

  onMounted(() => {
    if (!authStore.isAuthenticated) return
    void loadIncomingCount()
  })

  const links = computed<LibraryLink[]>(() => {
    const items: LibraryLink[] = [
      {
        id: 'browse',
        to: '/files',
        label: t('files.tabBrowse'),
        icon: FolderIcon,
        sidebarTestId: 'link-sidebar-v2-files-browse',
        mobileTestId: 'link-mobile-files-browse',
      },
      {
        id: 'incoming',
        to: '/files/incoming',
        label: t('files.tabIncoming'),
        icon: InboxArrowDownIcon,
        sidebarTestId: 'link-sidebar-v2-files-incoming',
        mobileTestId: 'link-mobile-files-incoming',
        badge: incomingCount.value > 0 ? incomingCount.value : undefined,
      },
      {
        id: 'generated',
        to: '/files/generated',
        label: t('files.tabGenerated'),
        icon: SparklesIcon,
        sidebarTestId: 'link-sidebar-v2-files-generated',
        mobileTestId: 'link-mobile-files-generated',
      },
    ]

    if (getConfigSync().features?.computeWorkspacesEnabled === true) {
      items.push({
        id: 'workspace',
        to: '/files/workspace',
        label: t('files.tabWorkspace'),
        icon: FolderPlusIcon,
        sidebarTestId: 'link-sidebar-v2-files-workspace',
        mobileTestId: 'link-mobile-files-workspace',
      })
    }

    items.push({
      id: 'search',
      to: '/files/search',
      label: t('files.tabSearch'),
      icon: MagnifyingGlassIcon,
      sidebarTestId: 'link-sidebar-v2-files-search',
      mobileTestId: 'link-mobile-files-search',
    })

    if (authStore.isAdmin) {
      items.push({
        id: 'vectors',
        to: '/files/vectors',
        label: t('files.tabVectors'),
        icon: CircleStackIcon,
        sidebarTestId: 'link-sidebar-v2-files-vectors',
        mobileTestId: 'link-mobile-files-vectors',
      })
    }

    return items
  })

  return { links }
}
