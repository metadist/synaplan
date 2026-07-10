<template>
  <!--
    Mobile push-drawer content (§4.3): primary navigation on phones. Rendered
    underneath the sliding content by MainLayout. A single scroll column holds
    the primary buttons, the expandable "More" section and the paginated chat
    history (infinite scroll). Hidden on md+ (desktop uses the SidebarV2 rail).
  -->
  <div class="v2-drawer-nav flex flex-col h-full" data-testid="nav-mobile-drawer-content">
    <!-- Clearance for the fixed toggle button + safe area -->
    <div class="v2-drawer-clearance flex-shrink-0" />

    <div
      ref="scrollContainer"
      class="flex-1 min-h-0 overflow-y-auto scroll-thin px-3 v2-drawer-scroll"
      data-testid="nav-mobile-drawer-scroll"
    >
      <!-- Primary actions -->
      <nav class="flex flex-col gap-1" :aria-label="$t('nav.menu')">
        <button
          class="v2-drawer-item v2-drawer-item--primary"
          :class="{ 'opacity-60': isCreatingChat }"
          :disabled="isCreatingChat"
          data-testid="btn-mobile-nav-new"
          @click="handleNewChat"
        >
          <Icon
            v-if="isCreatingChat"
            icon="mdi:loading"
            class="w-5 h-5 animate-spin"
            aria-hidden="true"
          />
          <PlusIcon v-else class="w-5 h-5" aria-hidden="true" />
          <span class="flex-1 text-left">{{ $t('chat.newChat') }}</span>
        </button>

        <button
          class="v2-drawer-item"
          :class="[filesActive && 'v2-drawer-item--active', isGuestMode && 'opacity-60']"
          data-testid="btn-mobile-nav-files"
          @click="handleFilesClick"
        >
          <FolderIcon class="w-5 h-5" aria-hidden="true" />
          <span class="flex-1 text-left">{{ $t('nav.files') }}</span>
        </button>

        <button
          class="v2-drawer-item"
          :class="(moreExpanded || moreActive || accountActive) && 'v2-drawer-item--active'"
          :aria-expanded="moreExpanded"
          data-testid="btn-mobile-nav-more"
          @click="toggleMore"
        >
          <Bars3Icon class="w-5 h-5" aria-hidden="true" />
          <span class="flex-1 text-left">{{ $t('nav.more') }}</span>
          <Icon
            icon="mdi:chevron-down"
            class="w-5 h-5 flex-shrink-0 transition-transform txt-secondary"
            :class="moreExpanded && 'rotate-180'"
            aria-hidden="true"
          />
        </button>

        <!-- Expanded: remaining sections + account block -->
        <Transition name="more-accordion">
          <div v-show="moreExpanded" class="more-accordion-wrap" data-testid="sheet-mobile-more">
            <div class="more-accordion-inner pt-1 pl-2">
              <div v-for="item in moreSections" :key="item.key" class="mb-0.5">
                <button
                  class="v2-drawer-subitem"
                  :class="[
                    isItemActive(item) ? 'text-[var(--brand)]' : 'txt-primary',
                    item.requiresAuth && isGuestMode && 'opacity-60',
                  ]"
                  :data-nav-active="isItemActive(item) ? 'true' : undefined"
                  :data-testid="`btn-mobile-more-${item.key}`"
                  @click="handleSectionClick(item)"
                >
                  <component :is="item.icon" class="w-5 h-5 flex-shrink-0" aria-hidden="true" />
                  <span class="flex-1 text-left truncate">{{ item.label }}</span>
                  <Icon
                    v-if="item.children && item.children.length > 0"
                    icon="mdi:chevron-down"
                    class="w-5 h-5 flex-shrink-0 transition-transform txt-secondary"
                    :class="expandedSection === item.key && 'rotate-180'"
                    aria-hidden="true"
                  />
                </button>

                <div v-if="expandedSection === item.key && item.children" class="pl-4 pb-1">
                  <router-link
                    v-for="child in item.children"
                    :key="child.key"
                    :to="child.path"
                    class="v2-drawer-child"
                    :class="
                      route.path === child.path
                        ? 'text-[var(--brand)] bg-[var(--brand)]/[0.06] font-medium'
                        : 'txt-secondary'
                    "
                    :data-nav-active="route.path === child.path ? 'true' : undefined"
                    :data-testid="`link-mobile-more-${child.key}`"
                    @click="closeDrawer"
                  >
                    <span
                      class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                      :class="
                        route.path === child.path ? 'bg-[var(--brand)]' : 'bg-current opacity-20'
                      "
                    />
                    <span class="flex-1 truncate">{{ child.label }}</span>
                    <span
                      v-if="child.badge"
                      class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-200 font-medium"
                    >
                      {{ child.badge }}
                    </span>
                  </router-link>
                </div>
              </div>

              <!-- Account block -->
              <div
                class="mt-1 pt-2 border-t border-light-border/10 dark:border-dark-border/10"
                data-testid="section-mobile-more-account"
              >
                <p
                  class="px-3 pt-1 pb-1.5 text-[10px] font-semibold txt-secondary uppercase tracking-wider opacity-60"
                >
                  {{ $t('nav.account') }}
                </p>

                <template v-if="isGuestMode">
                  <router-link
                    to="/register"
                    class="v2-drawer-account font-medium"
                    style="color: var(--brand)"
                    data-testid="btn-mobile-more-register"
                    @click="closeDrawer"
                  >
                    <Icon icon="mdi:account-plus-outline" class="w-5 h-5" />
                    <span>{{ $t('guest.featureGate.registerButton') }}</span>
                  </router-link>
                  <router-link
                    to="/login"
                    class="v2-drawer-account txt-primary"
                    data-testid="btn-mobile-more-login"
                    @click="closeDrawer"
                  >
                    <ArrowRightOnRectangleIcon class="w-5 h-5" />
                    <span>{{ $t('auth.signIn') }}</span>
                  </router-link>
                  <button
                    v-if="isNativeServerControlAvailable()"
                    class="v2-drawer-account txt-primary"
                    data-testid="btn-mobile-more-server"
                    @click="handleChangeServer"
                  >
                    <ServerIcon class="w-5 h-5" />
                    <span>{{ $t('nativeServer.changeServer') }}</span>
                  </button>
                </template>

                <template v-else>
                  <p class="px-3 pb-1.5 text-xs txt-secondary truncate">
                    {{ authStore.user?.email || '' }}
                  </p>
                  <button
                    class="v2-drawer-account"
                    :class="isPathActive('/profile') ? 'v2-drawer-account--active' : 'txt-primary'"
                    :data-nav-active="isPathActive('/profile') ? 'true' : undefined"
                    data-testid="btn-mobile-more-profile"
                    @click="handleNavigate('/profile')"
                  >
                    <UserCircleIcon class="w-5 h-5" />
                    <span>{{ $t('nav.profile') }}</span>
                  </button>
                  <button
                    v-if="isMemoryServiceAvailable"
                    class="v2-drawer-account"
                    :class="[
                      isPathActive('/memories') ? 'v2-drawer-account--active' : 'txt-primary',
                      { 'opacity-60': !memoriesEnabledForUser },
                    ]"
                    :data-nav-active="isPathActive('/memories') ? 'true' : undefined"
                    data-testid="btn-mobile-more-memories"
                    @click="handleOpenMemories"
                  >
                    <Icon icon="mdi:brain" class="w-5 h-5" />
                    <span>{{ $t('pageTitles.memories') }}</span>
                    <Icon
                      v-if="!memoriesEnabledForUser"
                      icon="mdi:lock"
                      class="w-4 h-4 ml-auto text-orange-500 dark:text-orange-400"
                    />
                  </button>
                  <button
                    class="v2-drawer-account"
                    :class="
                      isPathActive('/statistics') ? 'v2-drawer-account--active' : 'txt-primary'
                    "
                    :data-nav-active="isPathActive('/statistics') ? 'true' : undefined"
                    data-testid="btn-mobile-more-statistics"
                    @click="handleNavigate('/statistics')"
                  >
                    <ChartBarIcon class="w-5 h-5" />
                    <span>{{ $t('nav.statistics') }}</span>
                  </button>
                  <button
                    class="v2-drawer-account"
                    :class="isPathActive('/settings') ? 'v2-drawer-account--active' : 'txt-primary'"
                    :data-nav-active="isPathActive('/settings') ? 'true' : undefined"
                    data-testid="btn-mobile-more-preferences"
                    @click="handleNavigate('/settings')"
                  >
                    <Cog6ToothIcon class="w-5 h-5" />
                    <span>{{ $t('nav.preferences') }}</span>
                  </button>
                  <button
                    v-if="
                      !authStore.isAdmin &&
                      configStore.billing.enabled &&
                      purchaseAllowed &&
                      authStore.isPro
                    "
                    class="v2-drawer-account"
                    :class="
                      isPathActive('/subscription') ? 'v2-drawer-account--active' : 'txt-primary'
                    "
                    :data-nav-active="isPathActive('/subscription') ? 'true' : undefined"
                    data-testid="btn-mobile-more-subscription"
                    @click="handleNavigate('/subscription')"
                  >
                    <CreditCardIcon class="w-5 h-5" />
                    <span>{{ $t('nav.subscription') }}</span>
                  </button>
                  <button
                    v-if="
                      !authStore.isAdmin &&
                      configStore.billing.enabled &&
                      purchaseAllowed &&
                      !authStore.isPro
                    "
                    class="v2-drawer-account text-amber-600 dark:text-amber-400"
                    data-testid="btn-mobile-more-upgrade"
                    @click="handleNavigate('/subscription')"
                  >
                    <RocketLaunchIcon class="w-5 h-5" />
                    <span>{{ $t('nav.upgrade') }}</span>
                  </button>
                  <button
                    v-if="!isImpersonating"
                    class="v2-drawer-account text-red-500 dark:text-red-400"
                    data-testid="btn-mobile-more-logout"
                    @click="handleLogout"
                  >
                    <ArrowRightOnRectangleIcon class="w-5 h-5" />
                    <span>{{ $t('settings.logout') }}</span>
                  </button>
                </template>
              </div>
            </div>
          </div>
        </Transition>
      </nav>

      <!-- Chat history (paginated, infinite scroll) -->
      <div class="mt-4 pt-3 border-t border-black/[0.06] dark:border-white/[0.06]">
        <p
          class="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider txt-secondary opacity-70"
        >
          {{ $t('nav.history') }}
        </p>

        <div
          v-if="visibleChats.length === 0 && !chatsStore.historyLoading"
          class="flex flex-col items-center justify-center py-8 gap-2 text-center"
          data-testid="state-mobile-history-empty"
        >
          <ChatBubbleLeftRightIcon class="w-7 h-7 text-[var(--brand)]/50" />
          <p class="text-sm txt-secondary">{{ $t('chat.noChats') }}</p>
        </div>

        <div v-else class="space-y-0.5" role="list" data-testid="list-mobile-history">
          <div
            v-for="chat in visibleChats"
            :key="chat.id"
            role="listitem"
            class="group/chat relative flex items-center gap-2.5 px-2.5 py-2.5 rounded-xl cursor-pointer transition-colors"
            :class="
              chat.id === chatsStore.activeChatId
                ? 'bg-[var(--brand)]/[0.08]'
                : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
            "
            data-testid="row-mobile-history"
            @click="handleChatSelect(chat.id)"
          >
            <div
              class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
              :class="
                chat.id === chatsStore.activeChatId
                  ? 'bg-[var(--brand)]/15'
                  : 'bg-black/[0.04] dark:bg-white/[0.04]'
              "
            >
              <Icon
                v-if="getChannelIcon(chat)"
                :icon="getChannelIcon(chat)!"
                class="w-4 h-4"
                :class="getChannelIconClass(chat)"
              />
              <ChatBubbleLeftRightIcon
                v-else
                class="w-4 h-4"
                :class="
                  chat.id === chatsStore.activeChatId
                    ? 'text-[var(--brand)]'
                    : 'txt-secondary opacity-60'
                "
              />
            </div>

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
              <span class="text-[11px] txt-secondary">{{ formatTimestamp(chat.updatedAt) }}</span>
            </div>

            <div class="flex-shrink-0" @click.stop>
              <button
                class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                :class="chatMenuOpenId === chat.id && 'bg-black/5 dark:bg-white/5'"
                :aria-label="$t('nav.more')"
                data-testid="btn-mobile-history-row-menu"
                @click="toggleChatMenu(chat.id, $event)"
              >
                <Icon icon="mdi:dots-horizontal" class="w-4.5 h-4.5 txt-secondary" />
              </button>
            </div>
          </div>
        </div>

        <!-- Infinite-scroll sentinel + loading indicator -->
        <div
          ref="historySentinel"
          class="h-8 flex items-center justify-center"
          data-testid="sentinel-mobile-history"
        >
          <Icon
            v-if="chatsStore.historyLoading"
            icon="mdi:loading"
            class="w-5 h-5 animate-spin txt-secondary"
            aria-hidden="true"
          />
        </div>
      </div>
    </div>
  </div>

  <!-- Chat context menu (teleported to escape the scroll container clipping) -->
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
        <div class="fixed w-44 dropdown-panel origin-top-right" :style="chatMenuStyle" @click.stop>
          <button
            class="dropdown-item"
            data-testid="btn-mobile-history-share"
            @click="handleChatShare(chatMenuOpenId!)"
          >
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
    @shared="chatsStore.loadChatHistory(true)"
    @unshared="chatsStore.loadChatHistory(true)"
  />

  <!-- Guest hint popover -->
  <GuestHintPopover
    :is-open="featureGateOpen"
    :feature-key="featureGateKey"
    @close="featureGateOpen = false"
  />
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowRightOnRectangleIcon,
  Bars3Icon,
  ChartBarIcon,
  ChatBubbleLeftRightIcon,
  Cog6ToothIcon,
  CreditCardIcon,
  FolderIcon,
  PlusIcon,
  RocketLaunchIcon,
  ServerIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import {
  isNativeServerControlAvailable,
  isPurchaseAllowed,
  openNativeServerOverlay,
} from '../services/api/nativeServer'
import { useAuthStore } from '../stores/auth'
import { useChatsStore, isDefaultChatTitle, type Chat as StoreChat } from '../stores/chats'
import { useConfigStore } from '../stores/config'
import { useSidebarStore } from '../stores/sidebar'
import { triggerHapticImpact } from '../services/api/nativeHaptics'
import { useAuth } from '../composables/useAuth'
import { useNavItems, type NavItem } from '../composables/useNavItems'
import { useDialog } from '../composables/useDialog'
import { useDateFormat } from '@/composables/useDateFormat'
import { useI18n } from 'vue-i18n'
import GuestHintPopover from './guest/GuestHintPopover.vue'
import ChatShareModal from './ChatShareModal.vue'

const { t } = useI18n()
const { formatRelativeTime } = useDateFormat()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const configStore = useConfigStore()
const chatsStore = useChatsStore()

// No purchase path on a custom server in the native app (store IAP only).
const purchaseAllowed = isPurchaseAllowed()
const sidebarStore = useSidebarStore()
const dialog = useDialog()
const { logout, isImpersonating } = useAuth()
const { navItems, isItemActive, isGuestMode } = useNavItems()

const moreExpanded = ref(false)
const expandedSection = ref<string | null>(null)
const isCreatingChat = ref(false)
const featureGateOpen = ref(false)
const featureGateKey = ref('general')

const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref('')
const chatMenuOpenId = ref<number | null>(null)
const chatMenuStyle = ref<Record<string, string>>({})

const scrollContainer = ref<HTMLElement | null>(null)
const historySentinel = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null

const isMemoryServiceAvailable = computed(() => configStore.features?.memoryService ?? false)
const memoriesEnabledForUser = computed(() => authStore.user?.memoriesEnabled !== false)

/** Everything that is not a primary button lands in the "More" section. */
const moreSections = computed(() =>
  navItems.value.filter((item) => item.key !== 'chat' && item.key !== 'files')
)

const filesActive = computed(() => route.path.startsWith('/files'))
const moreActive = computed(() => moreSections.value.some((item) => isItemActive(item)))

// Account-block entries live inside the "More" panel but outside navItems, so
// they need their own active tracking to keep "More" expanded and highlight the
// row the user is on (Profile, Memories, Statistics, Preferences, Subscription).
const isPathActive = (path: string) => route.path.startsWith(path)
const accountActive = computed(() =>
  ['/profile', '/memories', '/statistics', '/settings', '/subscription'].some(isPathActive)
)

// Widget sessions live in their dedicated view — never in the main history.
const visibleChats = computed(() => chatsStore.historyChats.filter((c) => !c.widgetSession))

const closeDrawer = () => sidebarStore.closeMobileDrawer()

// Navigation handlers close the drawer FIRST, then navigate: the close
// transition starts synchronously on tap (main thread still free) and the
// GPU-composited transform keeps sliding smoothly while the destination page
// renders. Navigating first would delay the animation behind that render.
const handleNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  closeDrawer()
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const handleFilesClick = () => {
  if (isGuestMode.value) {
    featureGateKey.value = 'files'
    featureGateOpen.value = true
    return
  }
  closeDrawer()
  router.push('/files')
}

const toggleMore = () => {
  triggerHapticImpact('light')
  moreExpanded.value = !moreExpanded.value
}

const handleSectionClick = async (item: NavItem) => {
  if (item.requiresAuth && isGuestMode.value) {
    featureGateKey.value = item.gateFeature || 'general'
    featureGateOpen.value = true
    return
  }
  if (item.children && item.children.length > 0) {
    triggerHapticImpact('light')
    expandedSection.value = expandedSection.value === item.key ? null : item.key
    return
  }
  closeDrawer()
  await router.push(item.path)
}

const handleNavigate = async (path: string) => {
  closeDrawer()
  await router.push(path)
}

const handleOpenMemories = () => {
  closeDrawer()
  if (!memoriesEnabledForUser.value) {
    router.push('/profile?highlight=memories')
    return
  }
  // Navigate to the dedicated memories page instead of opening a modal — the
  // drawer closes, the item shows active (isPathActive) and the global
  // router.afterEach haptic fires, consistent with every other nav item.
  router.push('/memories')
}

const handleLogout = async () => {
  closeDrawer()
  await logout()
  router.push('/login')
}

const handleChangeServer = () => {
  // Opens the native overlay rather than navigating, so close explicitly.
  closeDrawer()
  openNativeServerOverlay()
}

const getDisplayTitle = (chat: StoreChat): string => {
  if (!isDefaultChatTitle(chat.title, t('chat.newChat'))) return chat.title
  if (chat.firstMessagePreview) return chat.firstMessagePreview
  return t('chat.newChat')
}

const formatTimestamp = (dateStr: string): string => formatRelativeTime(new Date(dateStr))

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

const handleChatSelect = (chatId: number) => {
  closeDrawer()
  chatsStore.setActiveChat(chatId)
  if (route.path !== '/') router.push('/')
  chatMenuOpenId.value = null
}

const toggleChatMenu = (chatId: number, event: MouseEvent) => {
  triggerHapticImpact('light')
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
  chatMenuStyle.value = { top: `${top}px`, left: `${left}px` }
  chatMenuOpenId.value = chatId
}

const handleChatRename = async (chatId: number) => {
  const chat = chatsStore.historyChats.find((c) => c.id === chatId)
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
    await chatsStore.loadChatHistory(true)
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
    await chatsStore.loadChatHistory(true)
  }
}

const handleChatShare = (chatId: number) => {
  const chat = chatsStore.historyChats.find((c) => c.id === chatId)
  shareModalChatId.value = chatId
  shareModalChatTitle.value = chat?.title || 'Chat'
  shareModalOpen.value = true
  chatMenuOpenId.value = null
}

const setupObserver = () => {
  if (observer || !historySentinel.value) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((e) => e.isIntersecting) && sidebarStore.mobileDrawerOpen) {
        chatsStore.loadChatHistory(false)
      }
    },
    { root: scrollContainer.value, rootMargin: '200px 0px' }
  )
  observer.observe(historySentinel.value)
}

// Expand the drawer down to the page the user is currently on, so reopening
// the menu restores the path they navigated (e.g. More → Channels → Overview)
// instead of collapsing everything.
const syncExpansionToActiveRoute = () => {
  const activeSection = moreSections.value.find((item) => isItemActive(item))
  moreExpanded.value = activeSection !== undefined || accountActive.value
  expandedSection.value =
    activeSection?.children && activeSection.children.length > 0 ? activeSection.key : null
}

// Bring the active (deepest) nav entry into view once the sections are expanded.
const scrollActiveIntoView = () => {
  const container = scrollContainer.value
  if (!container) return
  const matches = container.querySelectorAll<HTMLElement>('[data-nav-active="true"]')
  matches[matches.length - 1]?.scrollIntoView({ block: 'center' })
}

// Keep the expansion synced to the active route at all times (the drawer is
// always mounted, just off-screen while closed). Doing it on navigation — not
// on open — means the sections are ALREADY expanded when the drawer slides in,
// so the user never sees the accordion animate open after navigating.
watch(() => route.path, syncExpansionToActiveRoute, { immediate: true })

// Refresh history and bring the active entry into view each time it opens.
watch(
  () => sidebarStore.mobileDrawerOpen,
  (open) => {
    if (open) {
      chatMenuOpenId.value = null
      // Guests have no authenticated chat history — calling the protected
      // endpoint would trigger checkAuthOrRedirect and bounce them to /login.
      if (!isGuestMode.value) {
        chatsStore.loadChatHistory(true)
      }
      nextTick(() => {
        setupObserver()
        scrollActiveIntoView()
      })
    }
  }
)

onMounted(() => {
  setupObserver()
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
})
</script>

<style scoped>
.v2-drawer-clearance {
  height: calc(env(safe-area-inset-top, 0px) + 60px);
}

.v2-drawer-scroll {
  padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
}

.v2-drawer-item {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  min-height: 48px;
  padding: 0.625rem 0.75rem;
  border-radius: 0.75rem;
  font-size: 0.9375rem;
  font-weight: 500;
  color: var(--txt-primary);
  transition: background-color 0.15s ease;
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}
.v2-drawer-item:hover {
  background: rgba(0, 0, 0, 0.03);
}
:global(.dark) .v2-drawer-item:hover {
  background: rgba(255, 255, 255, 0.03);
}
.v2-drawer-item--active {
  color: var(--brand);
  background: color-mix(in srgb, var(--brand) 8%, transparent);
}

/* Primary action ("New chat"): highlighted with a brand-colored border so it
   stands out from the plain nav items — no filled background. */
.v2-drawer-item--primary {
  border: 1px solid color-mix(in srgb, var(--brand) 45%, transparent);
  color: var(--brand);
}
.v2-drawer-item--primary:hover {
  background: color-mix(in srgb, var(--brand) 8%, transparent);
}

.v2-drawer-subitem {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  font-weight: 500;
  transition: background-color 0.15s ease;
  touch-action: manipulation;
}
.v2-drawer-subitem:hover {
  background: rgba(0, 0, 0, 0.03);
}
:global(.dark) .v2-drawer-subitem:hover {
  background: rgba(255, 255, 255, 0.03);
}

.v2-drawer-child {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border-radius: 0.625rem;
  font-size: 0.8125rem;
  transition: background-color 0.15s ease;
}

.v2-drawer-account {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  transition: background-color 0.15s ease;
  touch-action: manipulation;
}
.v2-drawer-account:hover {
  background: rgba(0, 0, 0, 0.03);
}
:global(.dark) .v2-drawer-account:hover {
  background: rgba(255, 255, 255, 0.03);
}
.v2-drawer-account--active {
  color: var(--brand);
  background: color-mix(in srgb, var(--brand) 8%, transparent);
  font-weight: 600;
}

/* "More" reveal animation: animate height:auto via the grid-rows 0fr↔1fr
   technique so the buttons below slide in smoothly. */
.more-accordion-wrap {
  display: grid;
  grid-template-rows: 1fr;
  transition:
    grid-template-rows 0.25s ease,
    opacity 0.2s ease;
  opacity: 1;
}
.more-accordion-inner {
  overflow: hidden;
  min-height: 0;
}
.more-accordion-enter-from,
.more-accordion-leave-to {
  grid-template-rows: 0fr;
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .more-accordion-wrap {
    transition: opacity 0.15s ease;
  }
}
</style>
