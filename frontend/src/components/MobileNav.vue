<template>
  <!--
    Mobile bottom tab bar (§4.3 #2): New · History · Files · More — the
    native pattern instead of a hidden drawer. Sits in normal flow below
    the content column, so it can never cover the chat input.
  -->
  <nav
    class="v2-mobile-tabbar md:hidden flex items-stretch justify-around flex-shrink-0"
    :aria-label="$t('nav.more')"
    data-testid="nav-mobile-bottom"
  >
    <button
      class="v2-mobile-tab v2-new-chat-btn-flat"
      :class="{ 'opacity-60': isCreatingChat }"
      :title="$t('nav.newDescription')"
      :disabled="isCreatingChat"
      data-testid="btn-mobile-nav-new"
      @click="handleNewChat"
    >
      <Icon
        v-if="isCreatingChat"
        icon="mdi:loading"
        class="w-6 h-6 animate-spin"
        aria-hidden="true"
      />
      <PlusIcon v-else class="w-6 h-6" aria-hidden="true" />
      <span class="v2-rail-label text-[10px] font-medium leading-tight">{{ $t('nav.new') }}</span>
    </button>

    <button
      class="v2-mobile-tab v2-rail-icon"
      :class="historyActive && 'v2-rail-icon--active'"
      :title="$t('nav.historyDescription')"
      data-testid="btn-mobile-nav-history"
      @click="sidebarStore.toggleChatSheet()"
    >
      <ClockIcon class="w-6 h-6" aria-hidden="true" />
      <span class="v2-rail-label text-[10px] font-medium leading-tight">
        {{ $t('nav.history') }}
      </span>
    </button>

    <button
      class="v2-mobile-tab v2-rail-icon relative"
      :class="filesActive && 'v2-rail-icon--active'"
      :title="$t('nav.filesDescription')"
      data-testid="btn-mobile-nav-files"
      @click="handleFilesClick"
    >
      <FolderIcon class="w-6 h-6" aria-hidden="true" />
      <span class="v2-rail-label text-[10px] font-medium leading-tight">
        {{ $t('nav.files') }}
      </span>
      <Icon
        v-if="isGuestMode"
        icon="mdi:lock-outline"
        class="absolute top-1 right-3 w-3.5 h-3.5 text-amber-500"
        aria-hidden="true"
      />
    </button>

    <button
      class="v2-mobile-tab v2-rail-icon"
      :class="(moreOpen || moreActive) && 'v2-rail-icon--active'"
      :title="$t('nav.moreDescription')"
      data-testid="btn-mobile-nav-more"
      @click="moreOpen = !moreOpen"
    >
      <Bars3Icon class="w-6 h-6" aria-hidden="true" />
      <span class="v2-rail-label text-[10px] font-medium leading-tight">{{ $t('nav.more') }}</span>
    </button>
  </nav>

  <!-- "More" bottom sheet: remaining sections as accordions + account block -->
  <Teleport to="#app">
    <Transition name="more-sheet">
      <div
        v-if="moreOpen"
        class="fixed inset-0 z-[100] flex items-end justify-center bg-black/40 backdrop-blur-sm md:hidden"
        data-testid="sheet-mobile-more-backdrop"
        @click.self="moreOpen = false"
      >
        <div
          class="w-full max-h-[85dvh] flex flex-col rounded-t-2xl shadow-2xl overflow-hidden bg-white/97 dark:bg-[#0e1628]/97 backdrop-blur-xl border-t border-white/20 dark:border-white/[0.08]"
          data-testid="sheet-mobile-more"
          @click.stop
        >
          <!-- Drag handle -->
          <div class="flex justify-center pt-2 pb-1">
            <div class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/10" />
          </div>

          <!-- Header -->
          <div class="flex-shrink-0 flex items-center justify-between px-4 pt-1 pb-2">
            <h2 class="text-lg font-bold txt-primary">{{ $t('nav.more') }}</h2>
            <button
              class="w-11 h-11 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors txt-secondary"
              :aria-label="$t('common.close')"
              data-testid="btn-mobile-more-close"
              @click="moreOpen = false"
            >
              <Icon icon="mdi:close" class="w-5 h-5" />
            </button>
          </div>

          <!-- Scrollable body -->
          <div class="flex-1 overflow-y-auto scroll-thin px-2 pb-2 more-sheet-safe-area">
            <!-- §4.3 #3: flyouts become accordions on touch -->
            <div v-for="item in moreSections" :key="item.key" class="mb-0.5">
              <button
                class="w-full min-h-[44px] flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.03]"
                :class="[
                  isItemActive(item) && 'text-[var(--brand)]',
                  ((item.requiresAuth && isGuestMode) || isItemLocked(item)) && 'opacity-60',
                ]"
                :title="item.description || item.label"
                :data-testid="`btn-mobile-more-${item.key}`"
                @click="handleSectionClick(item)"
              >
                <component :is="item.icon" class="w-5 h-5 flex-shrink-0" aria-hidden="true" />
                <span class="flex-1 text-left text-sm font-medium truncate">{{ item.label }}</span>
                <Icon
                  v-if="(item.requiresAuth && isGuestMode) || isItemLocked(item)"
                  icon="mdi:lock-outline"
                  class="w-4 h-4 text-amber-500 flex-shrink-0"
                  aria-hidden="true"
                />
                <Icon
                  v-else
                  icon="mdi:chevron-down"
                  class="w-5 h-5 flex-shrink-0 transition-transform txt-secondary"
                  :class="expandedSection === item.key && 'rotate-180'"
                  aria-hidden="true"
                />
              </button>

              <!-- Accordion children -->
              <div v-if="expandedSection === item.key && item.children" class="pl-4 pb-1">
                <router-link
                  v-for="child in item.children"
                  :key="child.key"
                  :to="child.path"
                  class="min-h-[44px] flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm transition-colors"
                  :class="
                    route.path === child.path
                      ? 'text-[var(--brand)] bg-[var(--brand)]/[0.06] font-medium'
                      : 'txt-secondary hover:txt-primary hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
                  "
                  :data-testid="`link-mobile-more-${child.key}`"
                  @click="moreOpen = false"
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
              class="mt-2 pt-2 border-t border-light-border/10 dark:border-dark-border/10"
              data-testid="section-mobile-more-account"
            >
              <p
                class="px-3 pt-1 pb-2 text-[10px] font-semibold txt-secondary uppercase tracking-wider opacity-60"
              >
                {{ $t('nav.account') }}
              </p>

              <!-- Guest: register / sign in -->
              <template v-if="isGuestMode">
                <router-link
                  to="/register"
                  class="more-account-row font-medium"
                  style="color: var(--brand)"
                  data-testid="btn-mobile-more-register"
                  @click="moreOpen = false"
                >
                  <Icon icon="mdi:account-plus-outline" class="w-5 h-5" />
                  <span>{{ $t('guest.featureGate.registerButton') }}</span>
                </router-link>
                <router-link
                  to="/login"
                  class="more-account-row"
                  data-testid="btn-mobile-more-login"
                  @click="moreOpen = false"
                >
                  <ArrowRightOnRectangleIcon class="w-5 h-5" />
                  <span>{{ $t('auth.signIn') }}</span>
                </router-link>
              </template>

              <!-- Authenticated account rows (mirrors the rail's avatar menu) -->
              <template v-else>
                <p class="px-3 pb-2 text-xs txt-secondary truncate">
                  {{ authStore.user?.email || '' }}
                </p>
                <button
                  class="more-account-row"
                  data-testid="btn-mobile-more-profile"
                  @click="handleNavigate('/profile')"
                >
                  <UserCircleIcon class="w-5 h-5" />
                  <span>{{ $t('nav.profile') }}</span>
                </button>
                <button
                  v-if="isMemoryServiceAvailable"
                  class="more-account-row"
                  :class="{ 'opacity-60': !memoriesEnabledForUser }"
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
                  class="more-account-row"
                  data-testid="btn-mobile-more-statistics"
                  @click="handleNavigate('/statistics')"
                >
                  <ChartBarIcon class="w-5 h-5" />
                  <span>{{ $t('nav.statistics') }}</span>
                </button>
                <button
                  class="more-account-row"
                  data-testid="btn-mobile-more-preferences"
                  @click="handleNavigate('/settings')"
                >
                  <Cog6ToothIcon class="w-5 h-5" />
                  <span>{{ $t('nav.preferences') }}</span>
                </button>
                <button
                  v-if="!authStore.isAdmin && configStore.billing.enabled && authStore.isPro"
                  class="more-account-row"
                  data-testid="btn-mobile-more-subscription"
                  @click="handleNavigate('/subscription')"
                >
                  <CreditCardIcon class="w-5 h-5" />
                  <span>{{ $t('nav.subscription') }}</span>
                </button>
                <button
                  v-if="!authStore.isAdmin && configStore.billing.enabled && !authStore.isPro"
                  class="more-account-row text-amber-600 dark:text-amber-400"
                  data-testid="btn-mobile-more-upgrade"
                  @click="handleNavigate('/subscription')"
                >
                  <RocketLaunchIcon class="w-5 h-5" />
                  <span>{{ $t('nav.upgrade') }}</span>
                </button>
                <button
                  v-if="!isImpersonating"
                  class="more-account-row text-red-500 dark:text-red-400"
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
      </div>
    </Transition>
  </Teleport>

  <!-- Memories Dialog (account block entry) -->
  <MemoriesDialog :is-open="isMemoriesDialogOpen" @close="isMemoriesDialogOpen = false" />

  <!-- Guest Feature Gate Modal -->
  <GuestFeatureGateModal
    :is-open="featureGateOpen"
    :feature-key="featureGateKey"
    @close="featureGateOpen = false"
  />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowRightOnRectangleIcon,
  Bars3Icon,
  ChartBarIcon,
  ClockIcon,
  Cog6ToothIcon,
  CreditCardIcon,
  FolderIcon,
  PlusIcon,
  RocketLaunchIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useAppModeStore } from '../stores/appMode'
import { useAuthStore } from '../stores/auth'
import { useChatsStore } from '../stores/chats'
import { useConfigStore } from '../stores/config'
import { useSidebarStore } from '../stores/sidebar'
import { useAuth } from '../composables/useAuth'
import { useDialog } from '../composables/useDialog'
import { useNavItems, type NavItem } from '../composables/useNavItems'
import GuestFeatureGateModal from './guest/GuestFeatureGateModal.vue'
import MemoriesDialog from './MemoriesDialog.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const configStore = useConfigStore()
const chatsStore = useChatsStore()
const sidebarStore = useSidebarStore()
const dialog = useDialog()
const { logout, isImpersonating } = useAuth()
const { navItems, isItemActive, isItemLocked, isGuestMode } = useNavItems()

const moreOpen = ref(false)
const expandedSection = ref<string | null>(null)
const isCreatingChat = ref(false)
const isMemoriesDialogOpen = ref(false)
const featureGateOpen = ref(false)
const featureGateKey = ref('general')

const isMemoryServiceAvailable = computed(() => configStore.features?.memoryService ?? false)
const memoriesEnabledForUser = computed(() => authStore.user?.memoriesEnabled !== false)

/** Everything that is not a bottom tab lands in the More sheet (§4.4). */
const moreSections = computed(() =>
  navItems.value.filter((item) => item.key !== 'chat' && item.key !== 'files')
)

const historyActive = computed(() => route.path === '/' || route.path.startsWith('/chat'))
const filesActive = computed(() => route.path.startsWith('/files'))
const moreActive = computed(() => moreSections.value.some((item) => isItemActive(item)))

const handleNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
    sidebarStore.closeChatSheet()
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
  router.push('/files')
}

const handleSectionClick = async (item: NavItem) => {
  if (item.requiresAuth && isGuestMode.value) {
    featureGateKey.value = item.gateFeature || 'general'
    featureGateOpen.value = true
    return
  }

  // Q6: locked in easy mode — same one-click switch as the rail.
  if (isItemLocked(item)) {
    const confirmed = await dialog.confirm({
      title: t('settings.appMode.lockedTitle'),
      message: t('settings.appMode.lockedMessage', { feature: item.label }),
      confirmText: t('settings.appMode.switchCta'),
      cancelText: t('common.cancel'),
    })
    if (!confirmed) return
    appModeStore.setMode('advanced')
  }

  if (item.children && item.children.length > 0) {
    expandedSection.value = expandedSection.value === item.key ? null : item.key
    return
  }

  moreOpen.value = false
  router.push(item.path)
}

const handleNavigate = (path: string) => {
  moreOpen.value = false
  router.push(path)
}

const handleOpenMemories = () => {
  moreOpen.value = false
  if (!memoriesEnabledForUser.value) {
    router.push('/profile?highlight=memories')
    return
  }
  isMemoriesDialogOpen.value = true
}

const handleLogout = async () => {
  moreOpen.value = false
  await logout()
  router.push('/login')
}

// Opening the sheet starts with the active section expanded.
watch(moreOpen, (open) => {
  if (open) {
    const active = moreSections.value.find((item) => isItemActive(item))
    expandedSection.value = active && !isItemLocked(active) ? active.key : null
  }
})

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && moreOpen.value) {
    moreOpen.value = false
  }
}

onMounted(() => document.addEventListener('keydown', handleEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape))
</script>

<style scoped>
.more-account-row {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  min-height: 44px;
  padding: 0.625rem 0.75rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  transition: background-color 0.15s ease;
}

.more-account-row:hover {
  background: rgba(0, 0, 0, 0.03);
}

:global(.dark) .more-account-row:hover {
  background: rgba(255, 255, 255, 0.03);
}

/* Keep rows above the iOS home indicator. */
.more-sheet-safe-area {
  padding-bottom: calc(0.5rem + env(safe-area-inset-bottom));
}

.more-sheet-enter-active,
.more-sheet-leave-active {
  transition: opacity 0.2s ease;
}
.more-sheet-enter-from,
.more-sheet-leave-to {
  opacity: 0;
}
.more-sheet-enter-active [data-testid='sheet-mobile-more'],
.more-sheet-leave-active [data-testid='sheet-mobile-more'] {
  transition: transform 0.25s ease;
}
.more-sheet-enter-from [data-testid='sheet-mobile-more'],
.more-sheet-leave-to [data-testid='sheet-mobile-more'] {
  transform: translateY(100%);
}
</style>
