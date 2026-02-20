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
      class="fixed inset-0 bg-black/50 z-40 md:hidden"
      data-testid="section-sidebar-v2-backdrop"
      @click="(closeFlyout(), sidebarStore.closeMobile())"
    />
  </Transition>

  <aside
    :class="[
      'v2-sidebar-rail flex flex-col h-screen',
      'fixed md:relative z-50 md:z-auto',
      'transition-transform duration-300 ease-in-out',
      sidebarStore.isMobileOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
    ]"
    style="width: 64px; min-width: 64px"
    data-testid="comp-sidebar-v2"
  >
    <!-- Top section — height-synced with Header (px-6 py-4 = 76px) -->
    <div
      class="flex flex-col items-center justify-center flex-shrink-0 border-b border-white/[0.04]"
      style="height: 76px"
    >
      <!-- Close button on mobile, logo on desktop -->
      <button
        class="md:hidden v2-rail-icon w-10 h-10 flex items-center justify-center"
        aria-label="Close sidebar"
        data-testid="btn-sidebar-v2-close"
        @click="sidebarStore.closeMobile()"
      >
        <Icon icon="mdi:close" class="w-5 h-5" />
      </button>
      <img :src="logoIconSrc" alt="synaplan" class="h-7 w-auto hidden md:block" />
    </div>

    <!-- New Chat Button -->
    <div class="flex items-center justify-center py-3 flex-shrink-0">
      <button
        class="v2-new-chat-btn w-10 h-10 flex items-center justify-center rounded-xl transition-all duration-200"
        :class="{ 'v2-new-chat-btn--creating': isCreatingChat }"
        :title="$t('chat.newChat')"
        :disabled="isCreatingChat"
        data-testid="btn-sidebar-v2-new-chat"
        @click="handleQuickNewChat"
      >
        <Icon
          :icon="isCreatingChat ? 'mdi:loading' : 'mdi:plus'"
          :class="['w-5 h-5', isCreatingChat && 'animate-spin']"
        />
      </button>
    </div>

    <!-- Nav Icons -->
    <nav class="flex-1 flex flex-col items-center gap-1 py-1 overflow-y-auto sidebar-scroll">
      <button
        v-for="item in navItems"
        :key="item.path"
        :ref="(el) => setNavBtnRef(el, item.path)"
        :class="[
          'v2-rail-icon w-10 h-10 flex items-center justify-center relative',
          isItemActive(item) && 'v2-rail-icon--active',
          item.isUpgrade && 'text-amber-500 dark:text-amber-400',
        ]"
        :title="item.label"
        :data-testid="`btn-sidebar-v2-${item.path.replace(/\//g, '-')}`"
        @click="handleNavClick(item)"
      >
        <component :is="item.icon" class="w-6 h-6" />
      </button>
    </nav>

    <!-- User Avatar -->
    <div class="flex items-center justify-center py-4 flex-shrink-0">
      <button
        ref="userBtnRef"
        class="v2-rail-icon w-10 h-10 flex items-center justify-center"
        :title="authStore.user?.email || ''"
        data-testid="btn-sidebar-v2-user"
        @click="toggleUserMenu"
      >
        <div
          class="w-8 h-8 rounded-full surface-chip flex items-center justify-center text-xs font-semibold"
        >
          {{ initials }}
        </div>
      </button>
    </div>
  </aside>

  <!-- User Dropdown (teleported to #app to escape local stacking context) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="userMenuOpen"
        class="fixed inset-0 z-[200]"
        data-testid="overlay-sidebar-v2-user"
        @click="userMenuOpen = false"
      >
        <div
          role="menu"
          class="fixed w-52 dropdown-panel origin-bottom-left"
          :style="userDropdownStyle"
          data-testid="dropdown-sidebar-v2-user"
          @click.stop
        >
          <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
            <p class="text-xs font-medium txt-primary truncate">
              {{ authStore.user?.email || 'guest@synaplan.com' }}
            </p>
          </div>
          <button
            role="menuitem"
            class="dropdown-item"
            data-testid="btn-sidebar-v2-profile"
            @click="handleProfileSettings"
          >
            <UserCircleIcon class="w-4 h-4" />
            <span>{{ $t('nav.profile') }}</span>
          </button>
          <button
            v-if="isMemoryServiceAvailable"
            role="menuitem"
            class="dropdown-item"
            :class="{ 'opacity-60': !memoriesEnabledForUser }"
            data-testid="btn-sidebar-v2-memories"
            @click="handleOpenMemories"
          >
            <Icon icon="mdi:brain" class="w-4 h-4" />
            <span>{{ $t('pageTitles.memories') }}</span>
            <Icon
              v-if="!memoriesEnabledForUser"
              icon="mdi:lock"
              class="w-3.5 h-3.5 ml-auto text-orange-500 dark:text-orange-400"
            />
          </button>
          <div class="border-t border-light-border/10 dark:border-dark-border/10">
            <button
              role="menuitem"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-statistics"
              @click="handleNavigate('/statistics')"
            >
              <ChartBarIcon class="w-4 h-4" />
              <span>{{ $t('nav.statistics') }}</span>
            </button>
            <button
              v-if="!authStore.isAdmin"
              role="menuitem"
              class="dropdown-item"
              :class="{ 'text-amber-500 dark:text-amber-400': !authStore.isPro }"
              data-testid="btn-sidebar-v2-subscription"
              @click="handleNavigate('/subscription')"
            >
              <SparklesIcon class="w-4 h-4" />
              <span>{{ authStore.isPro ? $t('nav.subscription') : $t('nav.upgrade') }}</span>
            </button>
          </div>
          <div class="border-t border-light-border/10 dark:border-dark-border/10">
            <button
              role="menuitem"
              class="dropdown-item text-red-500 dark:text-red-400"
              data-testid="btn-sidebar-v2-logout"
              @click="handleLogout"
            >
              <ArrowRightOnRectangleIcon class="w-4 h-4" />
              <span>{{ $t('settings.logout') }}</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Nav Children Dropdown (teleported to #app to escape local stacking context) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="activeFlyout === 'nav' && activeFlyoutItem"
        class="fixed inset-0 z-[200]"
        data-testid="overlay-sidebar-v2-nav"
        @click="closeFlyout"
      >
        <div
          class="fixed w-56 dropdown-panel origin-top-left overflow-hidden"
          :style="navDropdownStyle"
          data-testid="dropdown-sidebar-v2-nav"
          @click.stop
        >
          <!-- Header -->
          <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
            <p class="text-xs font-semibold txt-secondary uppercase tracking-wider">
              {{ activeFlyoutItem.label }}
            </p>
          </div>

          <!-- Children Links (with optional group headers) -->
          <div class="py-1 max-h-[60vh] overflow-y-auto scroll-thin">
            <template v-for="(section, sIdx) in groupedChildren" :key="sIdx">
              <div
                v-if="section.group"
                class="px-3 pt-2.5 pb-1"
                :class="{
                  'border-t border-light-border/10 dark:border-dark-border/10 mt-1': sIdx > 0,
                }"
              >
                <p
                  class="text-[10px] font-semibold txt-secondary uppercase tracking-wider opacity-60"
                >
                  {{ section.group }}
                </p>
              </div>
              <router-link
                v-for="child in section.items"
                :key="child.path"
                :to="child.path"
                class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors"
                :class="
                  route.path === child.path
                    ? 'text-[var(--brand)] bg-[var(--brand)]/[0.06] font-medium'
                    : 'txt-secondary hover:txt-primary hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
                "
                @click="(closeFlyout(), sidebarStore.closeMobile())"
              >
                <span
                  class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                  :class="route.path === child.path ? 'bg-[var(--brand)]' : 'bg-current opacity-20'"
                />
                <span class="flex-1 truncate">{{ child.label }}</span>
                <span
                  v-if="child.badge"
                  class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-200 font-medium"
                >
                  {{ child.badge }}
                </span>
              </router-link>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Chat Management Modal -->
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="chatModalOpen"
        class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-black/40 backdrop-blur-sm"
        data-testid="modal-chat-manager-backdrop"
        @click.self="chatModalOpen = false"
      >
        <div
          class="w-full sm:max-w-xl max-h-[90vh] sm:max-h-[70vh] flex flex-col rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden bg-white/95 dark:bg-[#0e1628]/95 backdrop-blur-xl border-t sm:border border-white/20 dark:border-white/[0.08] sm:m-4"
          data-testid="modal-chat-manager"
          @click.stop
        >
          <!-- Mobile drag handle -->
          <div class="sm:hidden flex justify-center pt-2 pb-1">
            <div class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/10" />
          </div>

          <!-- Header -->
          <div class="flex-shrink-0 px-4 pt-3 pb-3 sm:px-6 sm:pt-6 sm:pb-4">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-3">
                <div
                  class="w-9 h-9 rounded-xl bg-[var(--brand)]/10 flex items-center justify-center"
                >
                  <ChatBubbleLeftRightIcon class="w-5 h-5 text-[var(--brand)]" />
                </div>
                <div>
                  <h2 class="text-lg font-bold txt-primary leading-tight">
                    {{ $t('chat.recent') }}
                  </h2>
                  <p class="text-xs txt-secondary mt-0.5">
                    {{ chatList.length }}
                    {{ chatList.length === 1 ? 'conversation' : 'conversations' }}
                  </p>
                </div>
              </div>
              <button
                class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors txt-secondary"
                @click="chatModalOpen = false"
              >
                <Icon icon="mdi:close" class="w-5 h-5" />
              </button>
            </div>

            <!-- Search + New Chat Row -->
            <div class="flex items-center gap-2">
              <div class="flex-1 relative">
                <Icon
                  icon="mdi:magnify"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
                />
                <input
                  v-model="chatSearchQuery"
                  type="text"
                  class="w-full pl-9 pr-3 py-2 text-sm rounded-xl bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30 focus:border-[var(--brand)]/40 transition-all"
                  :placeholder="$t('chat.browser.searchPlaceholder')"
                />
              </div>
              <button
                class="flex-shrink-0 flex items-center gap-1.5 px-4 py-2 rounded-xl btn-primary text-sm font-medium transition-all hover:shadow-lg hover:shadow-brand/20"
                :disabled="isCreatingChat"
                data-testid="btn-chat-modal-new"
                @click="handleNewChat"
              >
                <Icon
                  :icon="isCreatingChat ? 'mdi:loading' : 'mdi:plus'"
                  :class="['w-4 h-4', isCreatingChat && 'animate-spin']"
                />
                <span class="hidden sm:inline">{{ $t('chat.newChat') }}</span>
              </button>
            </div>
          </div>

          <!-- Chat List -->
          <div class="flex-1 overflow-y-auto scroll-thin px-3 pb-4 sm:px-4">
            <!-- Empty State -->
            <div
              v-if="filteredChatList.length === 0 && chatSearchQuery"
              class="flex flex-col items-center justify-center py-10 gap-3"
            >
              <div
                class="w-12 h-12 rounded-2xl bg-black/[0.04] dark:bg-white/[0.04] flex items-center justify-center"
              >
                <Icon icon="mdi:chat-question-outline" class="w-6 h-6 txt-secondary" />
              </div>
              <p class="text-sm txt-secondary">{{ $t('common.noResults') }}</p>
            </div>
            <div
              v-else-if="filteredChatList.length === 0"
              class="flex flex-col items-center justify-center py-10 gap-3"
            >
              <div
                class="w-14 h-14 rounded-2xl bg-[var(--brand)]/[0.06] flex items-center justify-center"
              >
                <ChatBubbleLeftRightIcon class="w-7 h-7 text-[var(--brand)]/60" />
              </div>
              <div class="text-center">
                <p class="text-sm font-medium txt-primary">{{ $t('chat.noChats') }}</p>
                <p class="text-xs txt-secondary mt-1">{{ $t('chatInput.placeholder') }}</p>
              </div>
            </div>

            <!-- Chat Cards -->
            <div v-else class="space-y-1.5">
              <div
                v-for="chat in filteredChatList"
                :key="chat.id"
                class="group/chat relative flex items-center gap-3 px-3.5 py-3 rounded-xl cursor-pointer transition-all duration-150 active:scale-[0.99]"
                :class="
                  chat.id === chatsStore.activeChatId
                    ? 'bg-[var(--brand)]/[0.08] ring-1 ring-[var(--brand)]/20'
                    : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
                "
                @click="handleChatSelect(chat.id)"
              >
                <!-- Channel Indicator -->
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors"
                  :class="
                    chat.id === chatsStore.activeChatId
                      ? 'bg-[var(--brand)]/15'
                      : 'bg-black/[0.04] dark:bg-white/[0.04] group-hover/chat:bg-black/[0.06] dark:group-hover/chat:bg-white/[0.06]'
                  "
                >
                  <Icon
                    v-if="getChannelIcon(chat)"
                    :icon="getChannelIcon(chat)!"
                    class="w-4.5 h-4.5"
                    :class="getChannelIconClass(chat)"
                  />
                  <ChatBubbleLeftRightIcon
                    v-else
                    class="w-4.5 h-4.5"
                    :class="
                      chat.id === chatsStore.activeChatId
                        ? 'text-[var(--brand)]'
                        : 'txt-secondary opacity-60'
                    "
                  />
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <p
                    class="text-[13px] leading-snug truncate"
                    :class="
                      chat.id === chatsStore.activeChatId
                        ? 'font-semibold text-[var(--brand)]'
                        : 'font-medium txt-primary'
                    "
                  >
                    {{ getDisplayTitle(chat) }}
                  </p>
                  <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-[11px] txt-secondary">{{
                      formatTimestamp(chat.createdAt)
                    }}</span>
                    <span v-if="chat.messageCount" class="text-[11px] txt-secondary opacity-60">
                      · {{ chat.messageCount }} msg
                    </span>
                    <span
                      v-if="chat.isShared"
                      class="text-[11px] text-[var(--brand)] flex items-center gap-0.5"
                    >
                      <Icon icon="mdi:link-variant" class="w-3 h-3" />
                    </span>
                  </div>
                </div>

                <!-- Actions -->
                <div class="flex-shrink-0" @click.stop>
                  <button
                    class="w-9 h-9 sm:w-8 sm:h-8 rounded-lg flex items-center justify-center sm:opacity-0 sm:group-hover/chat:opacity-100 focus:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 transition-all"
                    :class="chatMenuOpenId === chat.id && '!opacity-100 bg-black/5 dark:bg-white/5'"
                    @click="toggleChatMenu(chat.id, $event)"
                  >
                    <Icon icon="mdi:dots-horizontal" class="w-4.5 h-4.5 txt-secondary" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div
            v-if="chatList.length > 5"
            class="flex-shrink-0 px-4 py-3 sm:px-5 border-t border-black/[0.04] dark:border-white/[0.04]"
          >
            <button
              class="w-full flex items-center justify-center gap-2 px-4 py-2.5 sm:py-2 rounded-xl text-sm sm:text-xs font-medium text-[var(--brand)] bg-[var(--brand)]/[0.06] hover:bg-[var(--brand)]/[0.12] active:bg-[var(--brand)]/[0.18] transition-all duration-150 group/show"
              @click="((chatModalOpen = false), $router.push('/statistics#chats'))"
            >
              <ChartBarIcon class="w-4 h-4 sm:w-3.5 sm:h-3.5 opacity-70" />
              {{ $t('chat.showAll') }}
              <Icon
                icon="mdi:arrow-right"
                class="w-4 h-4 sm:w-3.5 sm:h-3.5 transition-transform duration-150 group-hover/show:translate-x-0.5"
              />
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Chat Context Menu (teleported to escape overflow clipping) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="chatMenuOpenId !== null"
        class="fixed inset-0 z-[150]"
        @click="chatMenuOpenId = null"
      >
        <div
          class="fixed w-44 dropdown-panel origin-top-right"
          :style="chatMenuStyle"
          @click.stop
        >
          <button class="dropdown-item" @click="handleChatShare(chatMenuOpenId!)">
            <Icon icon="mdi:share-variant-outline" class="w-4 h-4" />
            {{ $t('common.share') }}
          </button>
          <button class="dropdown-item" @click="handleChatRename(chatMenuOpenId!)">
            <Icon icon="mdi:pencil-outline" class="w-4 h-4" />
            {{ $t('common.rename') }}
          </button>
          <button
            class="dropdown-item dropdown-item--danger"
            @click="handleChatDelete(chatMenuOpenId!)"
          >
            <Icon icon="mdi:delete-outline" class="w-4 h-4" />
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Chat Share Modal -->
  <ChatShareModal
    :is-open="shareModalOpen"
    :chat-id="shareModalChatId"
    :chat-title="shareModalChatTitle"
    @close="shareModalOpen = false"
    @shared="chatsStore.loadChats()"
    @unshared="chatsStore.loadChats()"
  />

  <!-- Memories Dialog -->
  <MemoriesDialog :is-open="isMemoriesDialogOpen" @close="isMemoriesDialogOpen = false" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ChatBubbleLeftRightIcon,
  FolderIcon,
  Cog6ToothIcon,
  ChartBarIcon,
  ShieldCheckIcon,
  SparklesIcon,
  PuzzlePieceIcon,
  UserCircleIcon,
  ArrowRightOnRectangleIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useSidebarStore } from '../stores/sidebar'
import { useAuthStore } from '../stores/auth'
import { useAppModeStore } from '../stores/appMode'
import { useConfigStore } from '../stores/config'
import { useAuth } from '../composables/useAuth'
import { useTheme } from '../composables/useTheme'
import { useChatsStore, type Chat as StoreChat } from '../stores/chats'
import { useDialog } from '../composables/useDialog'
import { getFeaturesStatus } from '../services/featuresService'
import { useI18n } from 'vue-i18n'
import MemoriesDialog from './MemoriesDialog.vue'
import ChatShareModal from './ChatShareModal.vue'

const { t } = useI18n()
const sidebarStore = useSidebarStore()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const configStore = useConfigStore()
const chatsStore = useChatsStore()
const dialog = useDialog()
const { logout } = useAuth()
const { theme } = useTheme()
const route = useRoute()
const router = useRouter()
const isMemoriesDialogOpen = ref(false)
const userMenuOpen = ref(false)
const userBtnRef = ref<HTMLElement | null>(null)
const userDropdownStyle = ref<Record<string, string>>({})
const navBtnRefs = ref<Record<string, HTMLElement | null>>({})
const navDropdownStyle = ref<Record<string, string>>({})

const setNavBtnRef = (el: unknown, path: string) => {
  navBtnRefs.value[path] = el as HTMLElement | null
}
const chatModalOpen = ref(false)
const chatMenuOpenId = ref<number | null>(null)
const chatMenuStyle = ref<Record<string, string>>({})
const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref('')
const isCreatingChat = ref(false)
const chatSearchQuery = ref('')

const isMemoryServiceAvailable = computed(() => configStore.features?.memoryService ?? false)
const memoriesEnabledForUser = computed(() => authStore.user?.memoriesEnabled !== false)

type FlyoutType = 'nav' | null
const activeFlyout = ref<FlyoutType>(null)
const activeFlyoutItem = ref<NavItem | null>(null)

const disabledFeaturesCount = ref(0)

const loadFeatureStatus = async () => {
  try {
    if (!import.meta.env.DEV) return
    if (!authStore.user || !authStore.isAuthenticated) return

    const status = await getFeaturesStatus()
    if (status && status.features) {
      disabledFeaturesCount.value = Object.values(status.features).filter((f) => !f.enabled).length
    } else {
      disabledFeaturesCount.value = 0
    }
  } catch {
    disabledFeaturesCount.value = 0
  }
}

onMounted(() => {
  loadFeatureStatus()
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
})

const toggleUserMenu = () => {
  if (!userMenuOpen.value && userBtnRef.value) {
    const rect = userBtnRef.value.getBoundingClientRect()
    const dropdownHeight = 280
    const spaceBelow = window.innerHeight - rect.bottom
    const spaceAbove = rect.top

    const left = `${rect.right + 8}px`

    if (spaceBelow >= dropdownHeight || spaceBelow >= spaceAbove) {
      const top = Math.min(rect.bottom - dropdownHeight, window.innerHeight - dropdownHeight - 8)
      userDropdownStyle.value = { left, top: `${Math.max(8, top)}px` }
    } else {
      const bottom = window.innerHeight - rect.top
      userDropdownStyle.value = { left, bottom: `${Math.max(8, bottom - dropdownHeight)}px` }
    }
  }
  userMenuOpen.value = !userMenuOpen.value
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    if (chatModalOpen.value) {
      chatModalOpen.value = false
      chatMenuOpenId.value = null
      return
    }
    closeFlyout()
    sidebarStore.closeMobile()
  }
}

const isDark = computed(() => {
  if (theme.value === 'dark') return true
  if (theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoIconSrc = computed(
  () =>
    `${import.meta.env.BASE_URL}${isDark.value ? 'single_bird-light.svg' : 'single_bird-dark.svg'}`
)

const initials = computed(() => {
  const email = authStore.user?.email || 'G'
  return email.charAt(0).toUpperCase()
})

interface NavChild {
  path: string
  label: string
  badge?: string
  group?: string
}

interface NavItem {
  path: string
  label: string
  icon: any
  isUpgrade?: boolean
  children?: NavChild[]
}

const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [{ path: '/', label: t('nav.chat'), icon: ChatBubbleLeftRightIcon }]

  items.push({ path: '/files', label: t('nav.files'), icon: FolderIcon })

  if (appModeStore.isAdvancedMode) {
    const settingsChildren: NavChild[] = [
      {
        path: '/tools/chat-widget',
        label: t('nav.toolsChatWidget'),
        group: t('nav.settingsChannels'),
      },
      {
        path: '/tools/mail-handler',
        label: t('nav.toolsMailHandler'),
        group: t('nav.settingsChannels'),
      },
      { path: '/config/inbound', label: t('nav.configInbound'), group: t('nav.settingsChannels') },
      {
        path: '/config/ai-models',
        label: t('nav.configAiModels'),
        group: t('nav.settingsAiTools'),
      },
      { path: '/config/api-keys', label: t('nav.configApiKeys'), group: t('nav.settingsAiTools') },
      {
        path: '/config/task-prompts',
        label: t('nav.configTaskPrompts'),
        group: t('nav.settingsAiTools'),
      },
      {
        path: '/config/sorting-prompt',
        label: t('nav.configSortingPrompt'),
        group: t('nav.settingsAiTools'),
      },
      {
        path: '/tools/doc-summary',
        label: t('nav.toolsDocSummary'),
        group: t('nav.settingsAiTools'),
      },
    ]

    items.push({
      path: '/settings',
      label: t('nav.settings'),
      icon: Cog6ToothIcon,
      children: settingsChildren,
    })
  }

  if (appModeStore.isAdvancedMode && configStore.plugins.length > 0) {
    items.push({
      path: '/plugins',
      label: t('nav.plugins'),
      icon: PuzzlePieceIcon,
      children: configStore.plugins.map((plugin: { name?: string }) => ({
        path: `/plugins/${plugin.name}`,
        label: plugin.name
          ? plugin.name.charAt(0).toUpperCase() + plugin.name.slice(1)
          : t('common.unknown'),
      })),
    })
  }

  if (authStore.isAdmin) {
    const adminChildren: NavChild[] = [{ path: '/admin', label: t('nav.adminDashboard') }]

    const featureStatusItem: NavChild = {
      path: '/admin/features',
      label: t('nav.adminFeatureStatus'),
    }
    if (disabledFeaturesCount.value > 0) {
      featureStatusItem.badge = String(disabledFeaturesCount.value)
    }
    adminChildren.push(featureStatusItem)
    adminChildren.push({ path: '/admin/config', label: t('nav.adminSystemConfig') })

    items.push({
      path: '/admin',
      label: t('nav.admin'),
      icon: ShieldCheckIcon,
      children: adminChildren,
    })
  }

  return items
})

const groupedChildren = computed(() => {
  if (!activeFlyoutItem.value?.children) return []
  const children = activeFlyoutItem.value.children
  const hasGroups = children.some((c) => c.group)
  if (!hasGroups) return [{ group: null, items: children }]

  const groups: Array<{ group: string | null; items: NavChild[] }> = []
  let currentGroup: string | null = null
  for (const child of children) {
    const g = child.group ?? null
    if (g !== currentGroup) {
      currentGroup = g
      groups.push({ group: g, items: [] })
    }
    groups[groups.length - 1].items.push(child)
  }
  return groups
})

const isItemActive = (item: NavItem): boolean => {
  if (item.path === '/') {
    return route.path === '/' || route.path.startsWith('/chat')
  }
  if (item.path === '/settings') {
    return route.path.startsWith('/tools') || route.path.startsWith('/config')
  }
  return route.path.startsWith(item.path)
}

const handleQuickNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  closeFlyout()
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
    chatModalOpen.value = false
    sidebarStore.closeMobile()
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const handleNavClick = (item: NavItem) => {
  userMenuOpen.value = false

  if (item.path === '/') {
    closeFlyout()
    chatSearchQuery.value = ''
    chatMenuOpenId.value = null
    chatModalOpen.value = !chatModalOpen.value
    if (chatModalOpen.value) {
      chatsStore.loadChats()
    }
    return
  }

  if (item.children && item.children.length > 0) {
    if (activeFlyout.value === 'nav' && activeFlyoutItem.value?.path === item.path) {
      closeFlyout()
    } else {
      const btn = navBtnRefs.value[item.path]
      if (btn) {
        const rect = btn.getBoundingClientRect()
        const estimatedHeight = (item.children.length + 1) * 36 + 16
        const maxTop = window.innerHeight - estimatedHeight - 8
        navDropdownStyle.value = {
          left: `${rect.right + 8}px`,
          top: `${Math.max(8, Math.min(rect.top, maxTop))}px`,
        }
      }
      activeFlyout.value = 'nav'
      activeFlyoutItem.value = item
    }
    return
  }

  closeFlyout()
  router.push(item.path)
  sidebarStore.closeMobile()
}

const handleNavigate = (path: string) => {
  userMenuOpen.value = false
  closeFlyout()
  router.push(path)
  sidebarStore.closeMobile()
}

const handleProfileSettings = () => {
  handleNavigate('/profile')
}

const handleOpenMemories = () => {
  userMenuOpen.value = false
  closeFlyout()
  if (!memoriesEnabledForUser.value) {
    router.push('/profile?highlight=memories')
    return
  }
  isMemoriesDialogOpen.value = true
}

const handleLogout = async () => {
  userMenuOpen.value = false
  closeFlyout()
  await logout()
  router.push('/login')
}

// Chat management
const chatList = computed(() => {
  return chatsStore.chats.filter((c) => {
    if (c.widgetSession) return false
    if (c.id === chatsStore.activeChatId) return true
    const isEmpty =
      (!c.messageCount || c.messageCount === 0) &&
      !c.firstMessagePreview &&
      (c.title === t('chat.newChat') ||
        c.title === 'New Chat' ||
        c.title === 'Neuer Chat' ||
        c.title.startsWith('Chat '))
    return !isEmpty
  })
})

const filteredChatList = computed(() => {
  const q = chatSearchQuery.value.toLowerCase().trim()
  if (!q) return chatList.value
  return chatList.value.filter((c) => {
    const title = c.firstMessagePreview || c.title
    return title.toLowerCase().includes(q)
  })
})

const getDisplayTitle = (chat: StoreChat): string => {
  if (chat.firstMessagePreview) return chat.firstMessagePreview
  const isDefault =
    chat.title === t('chat.newChat') ||
    chat.title === 'New Chat' ||
    chat.title === 'Neuer Chat' ||
    chat.title.startsWith('Chat ')
  if (!isDefault) return chat.title
  return t('chat.newChat')
}

const formatTimestamp = (dateStr: string): string => {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)
  if (diffMins < 1) return t('chat.timeNow')
  if (diffMins < 60) return t('chat.timeMinutes', { n: diffMins })
  if (diffHours < 24) return t('chat.timeHours', { n: diffHours })
  if (diffDays < 7) return t('chat.timeDays', { n: diffDays })
  return `${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`
}

const getChannelIcon = (chat: StoreChat): string | null => {
  switch (chat.source) {
    case 'whatsapp':
      return 'mdi:whatsapp'
    case 'email':
      return 'mdi:email-outline'
    case 'widget':
      return 'mdi:widgets-outline'
    default:
      return null
  }
}

const getChannelIconClass = (chat: StoreChat): string => {
  switch (chat.source) {
    case 'whatsapp':
      return 'text-green-500'
    case 'email':
      return 'text-blue-500'
    case 'widget':
      return 'text-purple-500'
    default:
      return ''
  }
}

const handleNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
    chatModalOpen.value = false
    sidebarStore.closeMobile()
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const handleChatSelect = (chatId: number) => {
  chatsStore.activeChatId = chatId
  if (route.path !== '/') router.push('/')
  chatModalOpen.value = false
  chatMenuOpenId.value = null
  sidebarStore.closeMobile()
}

const handleChatRename = async (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  chatMenuOpenId.value = null
  const newTitle = await dialog.prompt({
    title: t('chat.rename'),
    message: t('chat.enterNewName'),
    placeholder: t('chat.namePlaceholder'),
    defaultValue: chat?.title || '',
    confirmText: t('common.rename'),
    cancelText: t('common.cancel'),
  })
  if (newTitle && newTitle.trim()) {
    await chatsStore.updateChatTitle(chatId, newTitle.trim())
  }
}

const handleChatDelete = async (chatId: number) => {
  chatMenuOpenId.value = null
  const confirmed = await dialog.confirm({
    title: t('chat.delete'),
    message: t('chat.deleteConfirm'),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (confirmed) {
    await chatsStore.deleteChat(chatId)
  }
}

const handleChatShare = (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  shareModalChatId.value = chatId
  shareModalChatTitle.value = chat?.title || 'Chat'
  shareModalOpen.value = true
  chatMenuOpenId.value = null
}

const toggleChatMenu = (chatId: number, event: MouseEvent) => {
  if (chatMenuOpenId.value === chatId) {
    chatMenuOpenId.value = null
    return
  }
  const btn = event.currentTarget as HTMLElement
  const rect = btn.getBoundingClientRect()
  const menuHeight = 140
  const menuWidth = 176
  const spaceBelow = window.innerHeight - rect.bottom
  const top = spaceBelow < menuHeight ? rect.top - menuHeight : rect.bottom + 4
  const left = Math.max(8, rect.right - menuWidth)
  chatMenuStyle.value = {
    top: `${top}px`,
    left: `${left}px`,
  }
  chatMenuOpenId.value = chatId
}

const closeFlyout = () => {
  activeFlyout.value = null
  activeFlyoutItem.value = null
  userMenuOpen.value = false
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-active [data-testid='modal-chat-manager'],
.modal-leave-active [data-testid='modal-chat-manager'] {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}
.modal-enter-from [data-testid='modal-chat-manager'],
.modal-leave-to [data-testid='modal-chat-manager'] {
  transform: scale(0.95) translateY(10px);
  opacity: 0;
}
</style>
