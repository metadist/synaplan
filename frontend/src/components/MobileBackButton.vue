<template>
  <!--
    Native-shell back affordance. iOS has no hardware back button and on mobile
    the only chrome is the bottom tab bar (MobileNav) — so once a user pushes
    into a full-screen sub-view (Profile, Settings, Subscription, Admin, …) there
    is no way back. This floating chevron lives in the 62px top clearance that
    non-chat views already reserve on mobile (see style.css), so it never covers
    a page title. Shown only in the native app, only when there is in-app history
    to pop, and never on the chat/home surface (where it would overlap messages).
  -->
  <button
    v-if="showBack"
    type="button"
    class="mobile-back-btn fixed left-3 z-30 md:hidden flex items-center gap-0.5 h-10 pl-1.5 pr-3 rounded-full surface-card shadow-lg txt-primary active:scale-95 transition-transform"
    :aria-label="$t('common.back')"
    data-testid="btn-mobile-back"
    @click="goBack"
  >
    <Icon icon="mdi:chevron-left" class="w-6 h-6" aria-hidden="true" />
    <span class="text-sm font-medium">{{ $t('common.back') }}</span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { isNativeApp } from '@/services/api/nativeRuntime'

const route = useRoute()
const router = useRouter()
const native = isNativeApp()

/** The chat/home surface is the root; a back button there would overlap the
 *  scrolling message list (and is excluded from the 62px top clearance). */
const isChatSurface = computed(() => '/' === route.path || route.path.startsWith('/chat'))

/** Vue Router records the previous entry in the history state; `back` is null
 *  on the first entry / a cold deep-link, which is exactly when we hide. */
const canGoBack = computed(() => {
  void route.fullPath // re-evaluate on every navigation
  const state = router.options.history.state as { back?: unknown } | null
  return null != state && null != state.back
})

const showBack = computed(() => native && !isChatSurface.value && canGoBack.value)

function goBack(): void {
  const state = router.options.history.state as { back?: unknown } | null
  if (null != state && null != state.back) {
    router.back()
  } else {
    void router.push('/')
  }
}
</script>

<style scoped>
.mobile-back-btn {
  /* Sit inside the iOS safe area / notch, within the reserved top band. */
  top: calc(env(safe-area-inset-top, 0px) + 10px);
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}
</style>
