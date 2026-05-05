<template>
  <!--
    Persistent inset ring around the viewport — signals impersonation at all
    times without consuming any layout space (Tailwind's `ring` is a
    box-shadow, not a border) and `pointer-events: none` ensures it never
    blocks clicks on the underlying app. Rendered at z-[9998] so it sits on
    top of every UI chrome layer in the app (sidebar overlays go up to
    z-[200], the chat widget uses z-[9999]) — without this the navbar
    visually clipped the top edge of the ring.
  -->
  <div
    v-if="active"
    class="pointer-events-none fixed inset-0 z-[9998] ring-4 ring-inset ring-amber-500 dark:ring-amber-600"
    aria-hidden="true"
    data-testid="impersonation-ring"
  />

  <!--
    Compact floating pill carrying the banner content. Pinned top-center via
    `fixed`, so it never reserves vertical space in the document flow — the
    main app stays scroll-free and the navbar / profile icon are not pushed
    off-screen as the previous sticky banner used to do. Slides in from
    above so the appearance is still noticeable.

    z-[9999] matches the highest layer in the app (NotificationContainer,
    ChatWidget) so the pill never disappears behind a sidebar overlay or a
    sticky navbar. It is centered horizontally rather than pinned right to
    avoid colliding with toast notifications, which also live at
    top-right + z-[9999].
  -->
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="-translate-y-2 opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="transition duration-150 ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="-translate-y-2 opacity-0"
  >
    <div
      v-if="active"
      class="fixed top-3 left-1/2 z-[9999] flex max-w-[calc(100vw-1.5rem)] -translate-x-1/2 items-center gap-2 rounded-full bg-amber-500 px-3 py-1.5 text-amber-950 shadow-lg dark:bg-amber-600 dark:text-amber-50"
      role="alert"
      aria-live="polite"
      data-testid="banner-impersonation"
    >
      <Icon icon="mdi:incognito" class="h-4 w-4 shrink-0" aria-hidden="true" />
      <div class="min-w-0 text-xs leading-tight">
        <div class="truncate font-semibold" data-testid="banner-impersonation-target">
          {{ $t('admin.impersonate.banner.viewing', { email: user?.email ?? '—' }) }}
        </div>
        <div class="truncate text-[10px] opacity-80" data-testid="banner-impersonation-admin">
          {{ $t('admin.impersonate.banner.adminLabel', { email: impersonator?.email ?? '—' }) }}
        </div>
      </div>
      <button
        type="button"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-950/15 px-2.5 py-1 text-xs font-semibold transition hover:bg-amber-950/25 focus:outline-none focus:ring-2 focus:ring-amber-950/40 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/15 dark:hover:bg-white/25 dark:focus:ring-white/40"
        :disabled="exiting"
        :title="$t('admin.impersonate.banner.exitButtonTitle')"
        data-testid="btn-impersonation-exit"
        @click="onExit"
      >
        <Icon
          :icon="exiting ? 'mdi:loading' : 'mdi:logout-variant'"
          :class="['h-3.5 w-3.5', exiting && 'animate-spin']"
          aria-hidden="true"
        />
        {{ $t('admin.impersonate.banner.exitButton') }}
      </button>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'

import { useAuth } from '@/composables/useAuth'
import { useNotification } from '@/composables/useNotification'

/**
 * Persistent impersonation indicator.
 *
 * Replaces the older full-width sticky banner — that one reserved vertical
 * space at the top of the page and pushed the navbar / profile icon off the
 * viewport on shorter screens, forcing scroll. The current design is two
 * decoupled, layout-neutral elements:
 *
 *   1. A fixed inset ring around the viewport, drawn as a Tailwind `ring`
 *      (box-shadow) with `pointer-events: none`. It tints the chrome of the
 *      app in amber for the entire duration of the session, so the operator
 *      always sees they are not acting as themselves, but never blocks
 *      clicks on the underlying UI.
 *   2. A compact floating pill in the top-right corner with the same
 *      semantic content as the old banner (target user, original admin,
 *      Exit button). It is `fixed`-positioned, so it does not contribute to
 *      layout height — the page stays naturally scroll-free.
 *
 * The component still mounts ABOVE the ErrorBoundary in App.vue: even if a
 * route blows up, the admin must always be able to exit impersonation, so
 * we never want the pill rendered inside the boundary's slot.
 */

const { user, impersonator, isImpersonating, stopImpersonation } = useAuth()
const { t } = useI18n()
const { success, error } = useNotification()
const router = useRouter()

const exiting = ref(false)

/**
 * Single source of truth for the "render the indicator" decision so both
 * the ring and the pill flip on/off in lockstep without duplicating the
 * triple-condition check in the template.
 */
const active = computed(() => isImpersonating.value && !!impersonator.value && !!user.value)

async function onExit(): Promise<void> {
  if (exiting.value) return
  exiting.value = true
  try {
    const result = await stopImpersonation()
    if (result.success) {
      success(t('admin.impersonate.stopped'))
      // Send the admin back to the user list so they can either pick another
      // impersonation target or continue admin work without a stale view.
      await router.push({ name: 'admin', query: { tab: 'users' } }).catch(() => {})
    } else {
      error(result.error ?? t('admin.impersonate.stopFailed'))
    }
  } finally {
    exiting.value = false
  }
}
</script>
