<template>
  <div
    class="sidebar-panel-footer flex-shrink-0 border-t border-black/[0.06] dark:border-white/[0.06] px-4 pt-3 pb-3 flex flex-col gap-2"
    :class="contentClipped && 'is-clipped'"
    data-testid="section-sidebar-footer"
  >
    <button
      v-if="!isGuestMode"
      type="button"
      class="w-full flex items-center gap-2 min-h-11 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-secondary text-[15px] text-left focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
      :title="searchHint"
      :aria-label="searchHint"
      aria-keyshortcuts="Control+K Meta+K"
      data-testid="btn-sidebar-v2-search"
      data-tour="search"
      @click="smartSearchStore.open()"
    >
      <MagnifyingGlassIcon class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
      <span class="flex-1 truncate">{{ $t('search.palette.openButton') }}</span>
      <kbd class="font-sans text-[13px]">{{ searchShortcut }}</kbd>
    </button>

    <button
      v-if="showUpgrade"
      type="button"
      class="v2-upgrade-btn w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-[15px] font-medium min-h-11"
      data-testid="btn-sidebar-v2-upgrade"
      @click="go('/subscription')"
    >
      <RocketLaunchIcon class="w-5 h-5" aria-hidden="true" />
      {{ $t('nav.upgrade') }}
    </button>

    <button
      ref="userBtnRef"
      type="button"
      class="w-full inline-flex items-center gap-2 min-h-11 px-2 py-1.5 rounded-xl text-left hover:bg-black/[0.04] dark:hover:bg-white/[0.04] focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
      :title="authStore.user?.email || $t('nav.accountDescription')"
      :aria-label="authStore.user?.email || $t('nav.account')"
      :aria-expanded="userMenuOpen"
      aria-haspopup="menu"
      data-testid="btn-sidebar-v2-user"
      @click="toggleUserMenu"
    >
      <span
        class="sidebar-avatar w-8 h-8 flex-shrink-0 rounded-full flex items-center justify-center text-[13px] font-semibold"
      >
        {{ initials }}
      </span>
      <span class="flex-1 min-w-0">
        <span class="block text-[15px] font-medium txt-primary truncate">
          {{ authStore.user?.email || $t('nav.account') }}
        </span>
      </span>
      <ChevronUpIcon class="w-4 h-4 flex-shrink-0 txt-secondary" aria-hidden="true" />
    </button>
  </div>

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
          <template v-if="isGuestMode">
            <p class="px-3 py-2 text-[13px] font-medium txt-secondary">
              {{ $t('guest.banner.title') }}
            </p>
            <router-link
              v-if="configStore.auth.registrationEnabled"
              to="/register"
              class="dropdown-item font-medium"
              style="color: var(--brand)"
              data-testid="btn-sidebar-v2-guest-register"
              @click="userMenuOpen = false"
            >
              <Icon icon="mdi:account-plus-outline" class="w-4 h-4" />
              <span>{{ $t('guest.featureGate.registerButton') }}</span>
            </router-link>
            <router-link
              to="/login"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-guest-login"
              @click="userMenuOpen = false"
            >
              <ArrowRightOnRectangleIcon class="w-4 h-4" />
              <span>{{ $t('auth.signIn') }}</span>
            </router-link>
          </template>

          <template v-else>
            <button
              type="button"
              role="menuitem"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-profile"
              @click="go('/settings/profile')"
            >
              <UserCircleIcon class="w-4 h-4" />
              <span>{{ $t('nav.profile') }}</span>
            </button>
            <button
              v-if="!isImpersonating"
              type="button"
              role="menuitem"
              class="dropdown-item text-red-500 dark:text-red-400"
              data-testid="btn-sidebar-v2-logout"
              @click="handleLogout"
            >
              <ArrowRightOnRectangleIcon class="w-4 h-4" />
              <span>{{ $t('settings.logout') }}</span>
            </button>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowRightOnRectangleIcon,
  ChevronUpIcon,
  MagnifyingGlassIcon,
  RocketLaunchIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { useSmartSearchStore } from '@/stores/smartSearch'
import { useAuth } from '@/composables/useAuth'
import { paletteShortcutLabel } from '@/composables/search/shortcut'
import { isPurchaseAllowed } from '@/services/api/nativeServer'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

defineProps<{
  /** The list above still continues past this edge. */
  contentClipped?: boolean
}>()

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const configStore = useConfigStore()
const smartSearchStore = useSmartSearchStore()
const { logout, isImpersonating } = useAuth()

const isGuestMode = computed(() => !authStore.isAuthenticated)
const purchaseAllowed = isPurchaseAllowed()
const searchShortcut = computed(() => paletteShortcutLabel(t('search.palette.modifier')))
const searchHint = computed(() => t('search.palette.openHint', { shortcut: searchShortcut.value }))

const showUpgrade = computed(
  () =>
    !isGuestMode.value &&
    !authStore.isAdmin &&
    configStore.billing.enabled &&
    purchaseAllowed &&
    !authStore.isPro
)
const initials = computed(() => (authStore.user?.email || 'G').charAt(0).toUpperCase())

const userMenuOpen = ref(false)
const userBtnRef = ref<HTMLElement | null>(null)
const userDropdownStyle = ref<Record<string, string>>({})

const toggleUserMenu = () => {
  triggerHapticImpact('light')
  if (!userMenuOpen.value && userBtnRef.value) {
    const rect = userBtnRef.value.getBoundingClientRect()
    const width = 208
    const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8)
    userDropdownStyle.value = {
      left: `${left}px`,
      bottom: `${Math.max(8, window.innerHeight - rect.top + 8)}px`,
    }
  }
  userMenuOpen.value = !userMenuOpen.value
}

const go = (path: string) => {
  userMenuOpen.value = false
  router.push(path)
}

const handleLogout = async () => {
  userMenuOpen.value = false
  await logout()
  router.push('/login')
}

const onEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') userMenuOpen.value = false
}

const onResize = () => {
  userMenuOpen.value = false
}

onMounted(() => {
  document.addEventListener('keydown', onEscape)
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onEscape)
  window.removeEventListener('resize', onResize)
})
</script>

<style scoped>
/* Sits on the scroll edge, above the separator. Chats stay readable through
   it; it only says that the list continues. Gone once the last row is fully
   in view, so the final chat is never dimmed. */
.sidebar-panel-footer {
  position: relative;
  z-index: 2;
}

.sidebar-panel-footer.is-clipped::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 100%;
  height: 28px;
  pointer-events: none;
  /* The list fades into the panel color, with a darker band at the line so
     the footer reads as sitting on top of the chats. */
  background:
    linear-gradient(to top, rgba(41, 49, 65, 0.2), transparent 70%),
    linear-gradient(
      to top,
      var(--bg-sidebar-solid) 0%,
      color-mix(in srgb, var(--bg-sidebar-solid) 72%, transparent) 42%,
      transparent 100%
    );
}

.dark .sidebar-panel-footer.is-clipped::before {
  background:
    linear-gradient(to top, rgba(0, 0, 0, 0.45), transparent 70%),
    linear-gradient(
      to top,
      var(--bg-sidebar-solid) 0%,
      color-mix(in srgb, var(--bg-sidebar-solid) 80%, transparent) 42%,
      transparent 100%
    );
}

/* White on the light end of the gradient (#3b6fe0) is 4.6:1. The dark theme
   uses the light brand blue, so it takes dark ink (#0b1220 on #6d9ae0 is 6.5:1). */
.sidebar-avatar {
  color: #fff;
  background: linear-gradient(135deg, #003fc7, #3b6fe0);
  box-shadow: 0 2px 8px rgba(0, 63, 199, 0.25);
}

.dark .sidebar-avatar {
  color: #0b1220;
  background: linear-gradient(135deg, #6d9ae0, #93b8e8);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
}
</style>
