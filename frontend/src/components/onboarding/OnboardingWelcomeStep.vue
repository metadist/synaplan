<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-welcome">
    <!-- Title wordmark: "Welcome to" on line 1, the brand logo (which already
         contains the name) always wraps onto line 2 — no duplicate name. -->
    <div class="onb-enter-2">
      <h1 class="text-3xl font-bold txt-primary">
        {{ $t('onboarding.welcome.title') }}
      </h1>
      <div class="mt-4 flex justify-center">
        <img :src="logoSrc" :alt="config.branding.name" class="h-11" />
      </div>
    </div>
    <p class="text-base txt-secondary mt-4 leading-relaxed onb-enter-3">
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

    <!--
      MOBILE-APP SEAM (App Review 2.1 / 5.1.2(i), Play "prominent disclosure"):
      Synaplan answers through third-party AI models, so the first screen names
      what leaves the device before anything can be sent, and the CTA above is
      the affirmative act. Both stores allow the short form only as long as the
      substance stays on screen: it must say which data goes where and why, and
      must not live in a menu or in the privacy policy alone. So the sentence
      itself stays visible and only the elaboration moves behind "details".
    -->
    <p
      class="mt-8 text-[11px] leading-relaxed txt-secondary onb-enter-5"
      data-testid="section-onboarding-ai-notice"
    >
      {{ $t('onboarding.welcome.aiNotice') }}
      <button
        type="button"
        class="text-brand hover:underline underline-offset-2"
        data-testid="btn-onboarding-ai-details"
        @click="activeModal = 'ai'"
      >
        {{ $t('onboarding.welcome.aiNoticeDetails') }}
      </button>
      <span aria-hidden="true"> · </span>
      <a
        :href="config.branding.privacyUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="text-brand hover:underline underline-offset-2"
        data-testid="link-onboarding-privacy"
        >{{ $t('auth.privacyPolicy') }}</a
      >
    </p>

    <!-- Own-server modal: URL entry replacing the standard server. -->
    <OnboardingServerModal
      :is-open="activeModal === 'server'"
      @close="activeModal = null"
      @saved="activeModal = null"
    />

    <!-- Info-only modals: RAG, the chat widget, and the AI-processing detail. -->
    <OnboardingInfoModal
      :is-open="activeModal !== null && activeModal !== 'server'"
      :icon="activeInfoContent.icon"
      :title="activeInfoContent.title"
      :description="activeInfoContent.description"
      :points="activeInfoContent.points"
      :link-label="activeInfoContent.linkLabel"
      :link-url="activeInfoContent.linkUrl"
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
 * explain RAG and the chat widget. The AI-processing disclosure closes the page
 * as fine print, with its elaboration in a third info modal. All modals close
 * back to this page.
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

const activeModal = ref<'server' | 'rag' | 'widget' | 'ai' | null>(null)

interface InfoContent {
  icon: string
  title: string
  description: string
  points: string[]
  linkLabel?: string
  linkUrl?: string
}

const activeInfoContent = computed<InfoContent>(() => {
  if ('ai' === activeModal.value) {
    return {
      icon: 'mdi:shield-lock-outline',
      title: t('onboarding.ai.title'),
      description: t('onboarding.ai.body'),
      points: [t('onboarding.ai.point1'), t('onboarding.ai.point2'), t('onboarding.ai.point3')],
      linkLabel: t('auth.privacyPolicy'),
      linkUrl: config.branding.privacyUrl,
    }
  }
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
