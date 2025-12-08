<template>
  <!-- Backdrop for mobile -->
  <Transition
    enter-active-class="transition-opacity duration-300 ease-in-out"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-300 ease-in-out"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="sidebarStore.isMobileOpen"
      @click="sidebarStore.closeMobile()"
      class="fixed inset-0 bg-black/50 z-40 md:hidden"
      data-testid="section-sidebar-backdrop"
    />
  </Transition>

  <aside
    :class="[
      'bg-sidebar flex flex-col',
      'h-screen md:h-screen',
      'fixed md:relative z-50 md:z-auto',
      'transition-all duration-300 ease-in-out',
      sidebarStore.isMobileOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
      sidebarStore.isCollapsed ? 'md:w-20' : 'md:w-64',
      'w-64'
    ]"
    data-testid="comp-sidebar"
  >
    <div class="p-6 flex-shrink-0" data-testid="section-sidebar-header">
      <div class="flex items-center gap-3">
        <button
          v-if="sidebarStore.isCollapsed"
          @click="sidebarStore.toggleCollapsed()"
          class="h-8 w-8 hidden md:flex items-center justify-center txt-secondary hover-surface rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary flex-shrink-0"
          aria-label="Expand sidebar"
          data-testid="btn-sidebar-expand"
        >
          <Bars3Icon class="w-5 h-5" />
        </button>
        <template v-else>
          <img :src="logoSrc" alt="synaplan" class="h-8 flex-shrink-0" />
          <button
            @click="handleToggle"
            class="ml-auto h-8 w-8 flex items-center justify-center txt-secondary hover-surface rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary flex-shrink-0"
            aria-label="Collapse sidebar"
            data-testid="btn-sidebar-collapse"
          >
            <Bars3Icon class="w-5 h-5" />
          </button>
        </template>
      </div>
    </div>

    <div class="flex-1 min-h-0 flex flex-col">
      <div class="flex-1 overflow-y-auto sidebar-scroll px-3 py-4">
        <nav class="space-y-2" data-testid="nav-sidebar">
        <template v-for="item in navItems" :key="item.path">
          <router-link
            v-if="!item.children"
            :to="item.path"
            :class="[
              'group flex items-center gap-3 rounded-xl px-3 min-h-[42px]',
              item.isUpgrade ? 'btn-upgrade' : 'nav-item',
              sidebarStore.isCollapsed ? 'justify-center py-2' : 'py-2.5'
            ]"
            active-class="nav-item--active"
          >
            <component :is="item.icon" class="w-5 h-5 flex-shrink-0" />
            <span v-if="!sidebarStore.isCollapsed" class="font-medium text-sm truncate">{{ item.label }}</span>
          </router-link>

          <div v-else>
            <button
              @click="toggleMenu(item.path)"
              :class="[
                'w-full group flex items-center gap-3 rounded-xl px-3 min-h-[42px] nav-item',
                sidebarStore.isCollapsed ? 'justify-center py-2' : 'py-2.5 justify-between',
                isMenuExpanded(item.path) && 'nav-item--active'
              ]"
            >
              <div class="flex items-center gap-3">
                <component :is="item.icon" class="w-5 h-5 flex-shrink-0" />
                <span v-if="!sidebarStore.isCollapsed" class="font-medium text-sm truncate">{{ item.label }}</span>
              </div>
              <ChevronDownIcon 
                v-if="!sidebarStore.isCollapsed"
                :class="['w-4 h-4 transition-transform', isMenuExpanded(item.path) && 'rotate-180']"
              />
            </button>

            <div v-if="isMenuExpanded(item.path) && !sidebarStore.isCollapsed" class="mt-1 ml-8 space-y-1">
              <router-link
                v-for="child in item.children"
                :key="child.path"
                :to="child.path"
                class="sidebar-subitem flex items-center gap-2 px-3 py-2 rounded-lg text-sm txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                active-class="sidebar-subitem--active"
              >
                <span class="flex-1">{{ child.label }}</span>
                <span 
                  v-if="child.badge"
                  class="text-xs px-1.5 py-0.5 rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200"
                >
                  {{ child.badge }}
                </span>
              </router-link>
            </div>
          </div>
        </template>
        </nav>

        <div v-if="!sidebarStore.isCollapsed" class="mt-6 px-1" data-testid="section-sidebar-chatlist">
          <SidebarChatList />
        </div>

        <div class="h-20"></div>
      </div>

      <div class="sticky bottom-0 bg-sidebar p-4 border-t border-light-border/30 dark:border-dark-border/20 z-20" data-testid="section-sidebar-footer">
        <UserMenu :email="authStore.user?.email || 'guest@synaplan.com'" :collapsed="sidebarStore.isCollapsed" />
      </div>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ChatBubbleLeftRightIcon, WrenchScrewdriverIcon, FolderIcon, Cog6ToothIcon, ChartBarIcon, Bars3Icon, ChevronDownIcon, ShieldCheckIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'
import { useAuthStore } from '../stores/auth'
import { useAppModeStore } from '../stores/appMode'
import { useTheme } from '../composables/useTheme'
import { getFeaturesStatus } from '../services/featuresService'
import SidebarChatList from './SidebarChatList.vue'
import UserMenu from './UserMenu.vue'

const sidebarStore = useSidebarStore()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const { theme } = useTheme()
const route = useRoute()
const expandedMenus = ref<string[]>([])

// Feature Status
const disabledFeaturesCount = ref(0)

// Load feature status (only in development)
const loadFeatureStatus = async () => {
  try {
    // Only load in development mode
    const isDevelopment = import.meta.env.DEV
    if (!isDevelopment) {
      return
    }
    
    // Only load if user is authenticated
    if (!authStore.user || !authStore.isAuthenticated) {
      return
    }
    
    const status = await getFeaturesStatus()
    // Check if status and features exist
    if (status && status.features) {
      disabledFeaturesCount.value = Object.values(status.features).filter(f => !f.enabled).length
    } else {
      disabledFeaturesCount.value = 0
    }
  } catch (error) {
    console.error('Failed to load feature status:', error)
    // Silent fail - feature status is optional
    disabledFeaturesCount.value = 0
  }
}

// Load on mount
onMounted(() => {
  loadFeatureStatus()
  findParentMenu(route.path)
})

const isDark = computed(() => {
  if (theme.value === 'dark') return true
  if (theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => `${import.meta.env.BASE_URL}${isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg'}`)

interface NavItem {
  path: string
  label: string
  icon: any
  isUpgrade?: boolean
  children?: Array<{
    path: string
    label: string
    badge?: string
  }>
}

const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { path: '/', label: 'Chat', icon: ChatBubbleLeftRightIcon },
  ]

  // Tools: nur in Advanced Mode
  if (appModeStore.isAdvancedMode) {
    items.push({ 
      path: '/tools', 
      label: 'Tools', 
      icon: WrenchScrewdriverIcon,
      children: [
        { path: '/tools/introduction', label: 'Introduction' },
        { path: '/tools/chat-widget', label: 'Chat Widget' },
        { path: '/tools/doc-summary', label: 'Doc Summary' },
        { path: '/tools/mail-handler', label: 'Mail Handler' },
      ]
    })
  }

  // Files & RAG: Easy Mode = nur Files, Advanced = mit Submenu
  if (appModeStore.isEasyMode) {
    items.push({ path: '/files', label: 'Files', icon: FolderIcon })
  } else {
    items.push({ 
      path: '/files', 
      label: 'Files & RAG', 
      icon: FolderIcon,
      children: [
        { path: '/files', label: 'File Manager' },
        { path: '/rag', label: 'Semantic Search' },
      ]
    })
  }

  // AI Config: nur in Advanced Mode
  if (appModeStore.isAdvancedMode) {
    items.push({ 
      path: '/config', 
      label: 'AI Config', 
      icon: Cog6ToothIcon,
      children: [
        { path: '/config/inbound', label: 'Inbound' },
        { path: '/config/ai-models', label: 'AI Models' },
        { path: '/config/task-prompts', label: 'Task Prompts' },
        { path: '/config/sorting-prompt', label: 'Sorting Prompt' },
        { path: '/config/api-keys', label: 'API Keys' },
      ]
    })
  }

  items.push({ path: '/statistics', label: 'Statistics', icon: ChartBarIcon })

  // Subscription - show for all users (different label based on level)
  // Admin users don't need this as they have unlimited access
  if (!authStore.isAdmin) {
    const subscriptionLabel = authStore.isPro ? 'Subscription' : 'Upgrade'
    const isUpgradeStyle = !authStore.isPro
    items.push({ path: '/subscription', label: subscriptionLabel, icon: SparklesIcon, isUpgrade: isUpgradeStyle })
  }

  // Admin panel - only for admins
  if (authStore.isAdmin) {
    const adminChildren = [
      { path: '/admin', label: 'Dashboard' },
    ]
    
    // Feature Status in Admin (always available for admins)
    const featureStatusItem: { path: string; label: string; badge?: string } = {
      path: '/admin/features', 
      label: 'Feature Status'
    }
    if (disabledFeaturesCount.value > 0) {
      featureStatusItem.badge = String(disabledFeaturesCount.value)
    }
    adminChildren.push(featureStatusItem)
    
    items.push({ 
      path: '/admin', 
      label: 'Admin', 
      icon: ShieldCheckIcon,
      children: adminChildren
    })
  }

  return items
})

// Funktion zum Finden des übergeordneten Menüs basierend auf der aktuellen Route
const findParentMenu = (currentPath: string) => {
  for (const item of navItems.value) {
    if (item.children) {
      const isChildActive = item.children.some((child: { path: string; label: string; badge?: string }) => currentPath.startsWith(child.path))
      if (isChildActive && !expandedMenus.value.includes(item.path)) {
        expandedMenus.value.push(item.path)
      }
    }
  }
}

// Bei Routenänderungen das entsprechende Menü öffnen
watch(() => route.path, (newPath: string) => {
  findParentMenu(newPath)
}, { immediate: true })

const toggleMenu = (path: string) => {
  const index = expandedMenus.value.indexOf(path)
  if (index > -1) {
    expandedMenus.value.splice(index, 1)
  } else {
    expandedMenus.value.push(path)
  }
}

const isMenuExpanded = (path: string) => {
  return expandedMenus.value.includes(path)
}

const handleToggle = () => {
  if (window.innerWidth < 768) {
    sidebarStore.closeMobile()
  } else {
    sidebarStore.toggleCollapsed()
  }
}
</script>
