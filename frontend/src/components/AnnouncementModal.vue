<template>
  <Teleport to="#app">
    <Transition name="announcement">
      <div
        v-if="isOpen && announcement"
        class="modal-overlay fixed inset-0 z-[9999] flex items-center justify-center p-4"
        data-testid="modal-announcement"
      >
        <div
          class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"
          data-testid="modal-announcement-backdrop"
          @click="close"
        ></div>

        <div
          class="announcement-panel modal-panel relative surface-card shadow-2xl rounded-2xl w-full overflow-hidden overflow-y-auto"
          :class="announcement.image ? 'max-w-3xl' : 'max-w-md'"
          role="dialog"
          aria-modal="true"
          aria-labelledby="announcement-title"
          data-testid="modal-announcement-panel"
        >
          <button
            ref="closeButton"
            class="absolute top-3 right-3 z-10 p-1.5 rounded-lg icon-ghost"
            :aria-label="$t('common.close')"
            data-testid="btn-announcement-close"
            @click="close"
          >
            <XMarkIcon class="w-5 h-5" />
          </button>

          <!-- Illustrated announcements read as a spread: words on one side, the
               picture on the other. Without a picture the panel stays narrow. -->
          <div :class="announcement.image ? 'grid md:grid-cols-[1.15fr_1fr]' : ''">
            <div
              v-if="announcement.image"
              class="relative flex items-center justify-center overflow-hidden bg-gradient-to-br from-[var(--brand)]/25 via-[var(--brand)]/10 to-transparent pt-8 md:order-2 md:p-8 md:pt-8"
            >
              <img
                :src="announcement.image"
                alt=""
                class="h-56 w-auto md:h-96"
                data-testid="img-announcement"
              />
            </div>

            <div
              v-else
              class="flex h-32 items-center justify-center bg-gradient-to-br from-[var(--brand)]/20 to-[var(--brand)]/5"
            >
              <img :src="iconSrc" alt="" class="h-16 w-16" />
            </div>

            <div class="flex flex-col justify-center p-6 sm:p-8 md:order-1">
              <h2 id="announcement-title" class="text-lg font-bold txt-primary sm:text-2xl">
                {{ $t(`${announcement.i18nKey}.title`) }}
              </h2>
              <p class="mt-3 text-sm txt-secondary leading-relaxed">
                {{ $t(`${announcement.i18nKey}.body`) }}
              </p>

              <!-- Left-aligned with the text above it, so the main action leads.
                   The visitor's own store (see `useAnnouncements`) is first and
                   styled as primary; any other store is offered too, just less
                   emphasized, since a desktop visitor may want either link. -->
              <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-start">
                <a
                  v-for="(action, index) in announcement.actions"
                  :key="action.url"
                  :href="action.url"
                  target="_blank"
                  rel="noopener noreferrer"
                  :class="[
                    'px-4 py-2.5 rounded-xl text-sm font-semibold text-center',
                    0 === index ? 'btn-primary' : 'btn-secondary',
                  ]"
                  :data-testid="`link-announcement-action-${action.labelKey}`"
                  @click="close"
                >
                  {{ $t(`${announcement.i18nKey}.${action.labelKey}`) }}
                </a>
                <button
                  class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
                  data-testid="btn-announcement-later"
                  @click="close"
                >
                  {{ $t('announcements.later') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
/**
 * Shows the current one-time announcement, if there is one for this visitor.
 *
 * Mounted once in the app shell. Everything about *what* is announced lives in
 * `data/announcements.ts`; this component only presents it and records that the
 * user has now seen it.
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useAnnouncements } from '@/composables/useAnnouncements'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useTheme } from '@/composables/useTheme'
import { useEscapeKey } from '@/composables/useEscapeKey'

/**
 * Places where product news would interrupt rather than inform: someone signing
 * in, resetting a password, being onboarded, completing a purchase, or reading
 * a chat that was shared with them.
 */
/**
 * How long the page gets to itself before the announcement appears. Arriving in
 * the same frame as the app makes it read as an error dialog over a half-built
 * screen; a short pause lets the user see where they are first.
 */
const SETTLE_DELAY_MS = 1200

const QUIET_ROUTE_PREFIXES = [
  '/login',
  '/register',
  '/forgot-password',
  '/reset-password',
  '/onboarding',
  '/shared',
  '/account-deletion',
  '/subscription',
]

const route = useRoute()
const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)
const { current: announcement, dismiss } = useAnnouncements()

const closeButton = ref<HTMLButtonElement | null>(null)
const settled = ref(false)
let previouslyFocused: HTMLElement | null = null
let settleTimer: ReturnType<typeof setTimeout> | undefined

const isQuietRoute = computed(() =>
  QUIET_ROUTE_PREFIXES.some((prefix) => route.path.startsWith(prefix))
)

const isOpen = computed(() => settled.value && null !== announcement.value && !isQuietRoute.value)

onMounted(() => {
  settleTimer = setTimeout(() => (settled.value = true), SETTLE_DELAY_MS)
})

function close(): void {
  if (announcement.value) {
    dismiss(announcement.value.id)
  }
}

useEscapeKey(close, isOpen)

// Tracked so the page scroll is only released if this modal is what took it —
// other overlays lock it too, and clearing theirs would let the page slide
// around behind them.
let lockedScroll = false

function lockScroll(): void {
  // Someone else is already holding it. Claiming it a second time would make
  // this modal responsible for giving it back, and the page would start moving
  // behind whatever is still open.
  if ('hidden' === document.body.style.overflow) {
    return
  }

  document.body.style.overflow = 'hidden'
  lockedScroll = true
}

function releaseScroll(): void {
  if (lockedScroll) {
    document.body.style.overflow = ''
    lockedScroll = false
  }
}

watch(isOpen, async (open) => {
  if (open) {
    previouslyFocused = document.activeElement as HTMLElement | null
    lockScroll()
    await nextTick()
    closeButton.value?.focus()
    return
  }

  releaseScroll()
  previouslyFocused?.focus()
  previouslyFocused = null
})

onUnmounted(() => {
  clearTimeout(settleTimer)
  releaseScroll()
})
</script>

<style scoped>
.announcement-enter-active,
.announcement-leave-active {
  transition: opacity 0.2s ease;
}
.announcement-enter-from,
.announcement-leave-to {
  opacity: 0;
}
.announcement-enter-active .announcement-panel,
.announcement-leave-active .announcement-panel {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}
.announcement-enter-from .announcement-panel,
.announcement-leave-to .announcement-panel {
  transform: scale(0.95) translateY(-10px);
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .announcement-enter-active,
  .announcement-leave-active,
  .announcement-enter-active .announcement-panel,
  .announcement-leave-active .announcement-panel {
    transition: none;
  }
}
</style>
