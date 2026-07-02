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

    <div class="mt-10 space-y-4 text-left">
      <div
        v-for="(feature, index) in features"
        :key="feature.titleKey"
        class="flex items-start gap-4 p-4 rounded-2xl surface-card ring-1 ring-black/[0.04] dark:ring-white/[0.05]"
        :class="`onb-enter-${4 + index}`"
      >
        <div
          class="w-10 h-10 rounded-xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center flex-shrink-0"
        >
          <Icon :icon="feature.icon" class="w-5 h-5 text-brand" />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold txt-primary">{{ $t(feature.titleKey) }}</p>
          <p class="text-xs txt-secondary mt-0.5 leading-relaxed">{{ $t(feature.descKey) }}</p>
        </div>
      </div>
    </div>

    <button
      class="mt-10 w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98] onb-enter-7"
      data-testid="btn-welcome-next"
      @click="emit('next')"
    >
      {{ $t('onboarding.getStarted') }}
    </button>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), step 1: one welcome screen with the
 * value proposition — deliberately NOT a multi-slide feature tour (contextual
 * education already exists via HelpTour / promo tips).
 */
import { computed } from 'vue'
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
</script>

<style scoped>
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
.onb-enter-7 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.42s both;
}

@media (prefers-reduced-motion: reduce) {
  .onb-enter-1,
  .onb-enter-2,
  .onb-enter-3,
  .onb-enter-4,
  .onb-enter-5,
  .onb-enter-6,
  .onb-enter-7 {
    animation: none;
  }
}
</style>
