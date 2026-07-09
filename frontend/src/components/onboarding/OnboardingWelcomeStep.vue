<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-welcome">
    <div class="onb-enter-1 mb-8 flex justify-center">
      <img :src="logoSrc" :alt="config.branding.name" class="h-10" />
    </div>

    <h1 class="text-3xl font-bold txt-primary onb-enter-2">
      {{ $t('onboarding.welcome.title', { brand: config.branding.name }) }}
    </h1>
    <p class="text-base txt-secondary mt-3 leading-relaxed onb-enter-3">
      {{ $t('onboarding.welcome.subtitle') }}
    </p>

    <!-- Primary action: the focused "get started" CTA. -->
    <button
      class="mt-10 w-full py-3.5 rounded-xl btn-primary font-semibold text-base transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98] onb-enter-4"
      data-testid="btn-welcome-next"
      @click="emit('next')"
    >
      {{ $t('onboarding.getStarted') }}
    </button>

    <!-- Secondary, deliberately understated pill actions. They must not compete
         with the primary CTA — quiet chips that open a modal each. -->
    <div class="mt-5 flex flex-wrap items-center justify-center gap-2 onb-enter-5">
      <button
        v-if="serverControlAvailable"
        type="button"
        class="onboarding-pill"
        data-testid="btn-pill-server"
        @click="activeModal = 'server'"
      >
        <Icon icon="mdi:server-network" class="w-4 h-4" aria-hidden="true" />
        {{ $t('onboarding.welcome.pillServer') }}
      </button>
      <button
        type="button"
        class="onboarding-pill"
        data-testid="btn-pill-rag"
        @click="activeModal = 'rag'"
      >
        <Icon icon="mdi:database-search-outline" class="w-4 h-4" aria-hidden="true" />
        {{ $t('onboarding.welcome.pillRag') }}
      </button>
      <button
        type="button"
        class="onboarding-pill"
        data-testid="btn-pill-widget"
        @click="activeModal = 'widget'"
      >
        <Icon icon="mdi:message-text-outline" class="w-4 h-4" aria-hidden="true" />
        {{ $t('onboarding.welcome.pillWidget') }}
      </button>
    </div>

    <!-- Own-server modal: URL entry replacing the standard server. -->
    <OnboardingServerModal
      :is-open="activeModal === 'server'"
      @close="activeModal = null"
      @saved="activeModal = null"
    />

    <!-- Info-only modals for RAG and the chat widget. -->
    <OnboardingInfoModal
      :is-open="activeModal === 'rag' || activeModal === 'widget'"
      :icon="activeInfoContent.icon"
      :title="activeInfoContent.title"
      :description="activeInfoContent.description"
      :points="activeInfoContent.points"
      @close="activeModal = null"
    />
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), page 1: welcome + quiet entry points.
 *
 * One focused "get started" CTA advances to the plans page. Below it, a row of
 * deliberately understated pills open modals: "own server" (URL entry that
 * points the app at a self-hosted Synaplan server) and two info modals that
 * explain RAG and the chat widget. All modals close back to this page.
 */
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useTheme } from '@/composables/useTheme'
import { isNativeServerControlAvailable } from '@/services/api/nativeServer'
import OnboardingServerModal from '@/components/onboarding/OnboardingServerModal.vue'
import OnboardingInfoModal from '@/components/onboarding/OnboardingInfoModal.vue'

const emit = defineEmits<{ next: [] }>()

const { t } = useI18n()
const config = useConfigStore()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const { logoSrc } = useBrandLogo(isDark)

const serverControlAvailable = isNativeServerControlAvailable()

const activeModal = ref<'server' | 'rag' | 'widget' | null>(null)

interface InfoContent {
  icon: string
  title: string
  description: string
  points: string[]
}

const activeInfoContent = computed<InfoContent>(() => {
  if ('widget' === activeModal.value) {
    return {
      icon: 'mdi:message-text-outline',
      title: t('onboarding.widget.title'),
      description: t('onboarding.widget.body'),
      points: [
        t('onboarding.widget.point1'),
        t('onboarding.widget.point2'),
        t('onboarding.widget.point3'),
      ],
    }
  }
  return {
    icon: 'mdi:database-search-outline',
    title: t('onboarding.rag.title'),
    description: t('onboarding.rag.body'),
    points: [t('onboarding.rag.point1'), t('onboarding.rag.point2'), t('onboarding.rag.point3')],
  }
})
</script>

<style scoped>
/* Quiet pill: low-emphasis chip so it never competes with the primary CTA. */
.onboarding-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.4rem 0.85rem;
  border-radius: 9999px;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--txt-secondary);
  background-color: color-mix(in srgb, var(--txt-primary) 5%, transparent);
  transition:
    background-color 0.2s ease,
    color 0.2s ease,
    transform 0.2s ease;
}
.onboarding-pill:hover {
  color: var(--txt-primary);
  background-color: color-mix(in srgb, var(--txt-primary) 9%, transparent);
}
.onboarding-pill:active {
  transform: scale(0.97);
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

@media (prefers-reduced-motion: reduce) {
  .onb-enter-1,
  .onb-enter-2,
  .onb-enter-3,
  .onb-enter-4,
  .onb-enter-5 {
    animation: none;
  }
  .onboarding-pill {
    transition: none;
  }
}
</style>
