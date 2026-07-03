<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-welcome">
    <div class="onb-enter-1 mb-8 flex justify-center">
      <img :src="logoSrc" :alt="config.branding.name" class="h-9" />
    </div>

    <h1 class="text-3xl font-bold txt-primary onb-enter-2">
      {{ $t('onboarding.welcome.title', { brand: config.branding.name }) }}
    </h1>
    <p class="text-sm txt-secondary mt-2.5 onb-enter-3">
      {{ $t('onboarding.welcome.subtitle') }}
    </p>

    <!-- One rotating showcase card instead of a stacked feature list: the three
         value props cycle automatically (2026 onboarding best practice: show,
         don't list — minimal reading, one screen, one action). Tapping the card
         advances manually; auto-rotation pauses under prefers-reduced-motion. -->
    <button
      type="button"
      class="mt-10 w-full p-6 rounded-2xl surface-card ring-1 ring-black/[0.04] dark:ring-white/[0.05] onb-enter-4 min-h-[11rem] flex flex-col items-center justify-center"
      :aria-label="$t(activeFeature.titleKey)"
      data-testid="btn-feature-showcase"
      @click="advance"
    >
      <Transition name="feature" mode="out-in">
        <div :key="activeIndex" class="flex flex-col items-center">
          <div
            class="w-14 h-14 rounded-2xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center feature-icon"
          >
            <Icon :icon="activeFeature.icon" class="w-7 h-7 text-brand" />
          </div>
          <p class="text-base font-semibold txt-primary mt-4">
            {{ $t(activeFeature.titleKey) }}
          </p>
          <p class="text-sm txt-secondary mt-1.5 leading-relaxed">
            {{ $t(activeFeature.descKey) }}
          </p>
        </div>
      </Transition>
    </button>

    <!-- Feature dots: tiny progress indicator for the showcase (also tappable). -->
    <div
      class="mt-4 flex items-center justify-center gap-2 onb-enter-5"
      data-testid="section-feature-dots"
    >
      <button
        v-for="(feature, index) in features"
        :key="feature.titleKey"
        type="button"
        class="feature-dot"
        :class="{ 'feature-dot--active': index === activeIndex }"
        :aria-label="$t(feature.titleKey)"
        :aria-current="index === activeIndex ? 'true' : undefined"
        :data-testid="`btn-feature-dot-${index}`"
        @click="goTo(index)"
      />
    </div>

    <button
      class="mt-8 w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98] onb-enter-6"
      data-testid="btn-welcome-next"
      @click="emit('next')"
    >
      {{ $t('onboarding.getStarted') }}
    </button>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), step 1: one snappy welcome screen.
 * The three value props live in a single auto-rotating showcase card instead of
 * a stacked list — minimal reading, no decisions, one CTA. Rotation is paused
 * for users who prefer reduced motion; a tap on the card or a dot advances
 * manually and resets the timer.
 */
import { computed, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useTheme } from '@/composables/useTheme'

const emit = defineEmits<{ next: [] }>()

const config = useConfigStore()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const { logoSrc } = useBrandLogo(isDark)

const features = [
  {
    icon: 'mdi:chat-processing-outline',
    titleKey: 'onboarding.welcome.featureChatTitle',
    descKey: 'onboarding.welcome.featureChatDesc',
  },
  {
    icon: 'mdi:image-multiple-outline',
    titleKey: 'onboarding.welcome.featureMediaTitle',
    descKey: 'onboarding.welcome.featureMediaDesc',
  },
  {
    icon: 'mdi:file-document-outline',
    titleKey: 'onboarding.welcome.featureKnowledgeTitle',
    descKey: 'onboarding.welcome.featureKnowledgeDesc',
  },
]

const ROTATE_INTERVAL_MS = 3200

const activeIndex = ref(0)
const activeFeature = computed(() => features[activeIndex.value])

/** Auto-changing content is disorienting with reduced motion — rotate only on tap then. */
const prefersReducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches

let rotateTimer: ReturnType<typeof setInterval> | null = null

function startRotation() {
  if (prefersReducedMotion) return
  stopRotation()
  rotateTimer = setInterval(() => {
    activeIndex.value = (activeIndex.value + 1) % features.length
  }, ROTATE_INTERVAL_MS)
}

function stopRotation() {
  if (rotateTimer) {
    clearInterval(rotateTimer)
    rotateTimer = null
  }
}

/** Manual advance (card tap) — also resets the timer so it doesn't double-jump. */
function advance() {
  activeIndex.value = (activeIndex.value + 1) % features.length
  startRotation()
}

function goTo(index: number) {
  activeIndex.value = index
  startRotation()
}

startRotation()
onUnmounted(stopRotation)
</script>

<style scoped>
/* Showcase content swap: quick fade + slight rise. */
.feature-enter-active {
  transition:
    opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1),
    transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.feature-leave-active {
  transition:
    opacity 0.18s ease-in,
    transform 0.18s ease-in;
}
.feature-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.feature-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

/* Gentle breathing on the icon container — alive, not distracting. */
@keyframes featurePulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.06);
  }
}
.feature-icon {
  animation: featurePulse 3.2s ease-in-out infinite;
}

/* Showcase dots: same visual family as the step progress dots. */
.feature-dot {
  height: 6px;
  width: 6px;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--brand) 25%, transparent);
  transition:
    width 0.3s cubic-bezier(0.16, 1, 0.3, 1),
    background-color 0.3s ease;
}
.feature-dot--active {
  width: 18px;
  background-color: var(--brand);
}

/* Staggered enter, same family as the auth pages' entry animations. */
@keyframes onbEnter {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.onb-enter-1 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.05s both;
}
.onb-enter-2 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.12s both;
}
.onb-enter-3 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.18s both;
}
.onb-enter-4 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.24s both;
}
.onb-enter-5 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both;
}
.onb-enter-6 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.36s both;
}

@media (prefers-reduced-motion: reduce) {
  .onb-enter-1,
  .onb-enter-2,
  .onb-enter-3,
  .onb-enter-4,
  .onb-enter-5,
  .onb-enter-6,
  .feature-icon {
    animation: none;
  }
  .feature-enter-active,
  .feature-leave-active,
  .feature-dot {
    transition: none;
  }
  .feature-enter-from,
  .feature-leave-to {
    transform: none;
  }
}
</style>
