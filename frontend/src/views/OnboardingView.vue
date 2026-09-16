<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex flex-col relative overflow-hidden"
    :style="{ paddingTop: 'calc(env(safe-area-inset-top, 0px) + 1rem)' }"
    data-testid="page-onboarding"
  >
    <!-- Ambient background (same family as the auth pages) -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute -top-24 left-1/4 w-[28rem] h-[28rem] bg-brand/6 dark:bg-brand/12 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute -bottom-24 right-1/4 w-[28rem] h-[28rem] bg-brand/4 dark:bg-brand/8 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>

    <!-- Top controls: language picker. A dropdown so the user can jump straight
         to their language instead of cycling through all of them. -->
    <div class="relative z-30 flex items-center justify-between px-6 pt-2">
      <div ref="languageMenuRef" class="relative">
        <button
          class="h-9 pl-2.5 pr-2 rounded-lg surface-card ring-1 ring-black/[0.06] dark:ring-white/[0.1] shadow-sm txt-primary text-sm font-medium inline-flex items-center gap-1.5 transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.05]"
          data-testid="btn-language-toggle"
          :aria-expanded="languageMenuOpen"
          aria-haspopup="listbox"
          @click="languageMenuOpen = !languageMenuOpen"
        >
          <span aria-hidden="true">{{ currentLanguageOption.flag }}</span>
          <span>{{ currentLanguageOption.label }}</span>
          <Icon
            :icon="languageMenuOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'"
            class="w-4 h-4 txt-secondary"
            aria-hidden="true"
          />
        </button>
        <Transition name="lang-menu">
          <ul
            v-if="languageMenuOpen"
            class="absolute left-0 mt-2 w-44 rounded-xl surface-elevated ring-1 ring-black/[0.06] dark:ring-white/[0.1] shadow-lg overflow-hidden py-1"
            role="listbox"
            data-testid="menu-language"
          >
            <li v-for="lang in languages" :key="lang.value">
              <button
                class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-left transition-colors hover:bg-black/[0.04] dark:hover:bg-white/[0.06]"
                :class="
                  lang.value === currentLanguage ? 'txt-primary font-semibold' : 'txt-secondary'
                "
                role="option"
                :aria-selected="lang.value === currentLanguage"
                :data-testid="`btn-language-${lang.value}`"
                @click="selectLanguage(lang.value)"
              >
                <span class="text-base" aria-hidden="true">{{ lang.flag }}</span>
                <span class="flex-1">{{ lang.label }}</span>
                <Icon
                  v-if="lang.value === currentLanguage"
                  icon="mdi:check"
                  class="w-4 h-4 text-brand"
                  aria-hidden="true"
                />
              </button>
            </li>
          </ul>
        </Transition>
      </div>
    </div>

    <!-- Step content. The page is designed to fit a phone viewport without
         scrolling; min-h-0 + overflow-y-auto is only the fail-safe so nothing
         can ever be clipped unreachable on very small screens (the root has
         overflow-hidden for the ambient blobs). m-auto centers when the
         content is shorter than the viewport. -->
    <div
      class="relative z-10 flex-1 min-h-0 overflow-y-auto flex flex-col px-6 py-4"
      :style="{ paddingBottom: 'calc(env(safe-area-inset-bottom, 0px) + 1.25rem)' }"
    >
      <div class="m-auto w-full flex flex-col items-center">
        <OnboardingWelcomeStep @next="finish" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding): native-only first-run flow.
 *
 * A single welcome page (2026 onboarding best practice: minimal friction
 * before first value, no forced sign-up): what the app is, one focused
 * "get started" CTA, plus quiet pills that open modals (own server URL entry,
 * RAG info, chat widget info). The CTA enters the app right away — the chat
 * runs as a guest, so no account is needed to see the first value.
 *
 * The router guard only sends true first-run native users here; finishing
 * persists completion so the flow never shows again.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import OnboardingWelcomeStep from '@/components/onboarding/OnboardingWelcomeStep.vue'
import { markOnboardingCompleted } from '@/composables/useOnboarding'
import { languageOptions } from '@/i18n'

const router = useRouter()
const { locale } = useI18n()

const languages = languageOptions

const currentLanguage = computed(() => locale.value)
const currentLanguageOption = computed(
  () => languages.find((lang) => lang.value === locale.value) ?? languages[1]
)

const languageMenuOpen = ref(false)
const languageMenuRef = ref<HTMLElement | null>(null)

const selectLanguage = (value: string) => {
  locale.value = value
  localStorage.setItem('language', value)
  languageMenuOpen.value = false
}

const handleClickOutsideLanguageMenu = (event: MouseEvent) => {
  if (!languageMenuOpen.value) return
  if (languageMenuRef.value && !languageMenuRef.value.contains(event.target as Node)) {
    languageMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutsideLanguageMenu)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutsideLanguageMenu)
})

/** Enter the app as a guest; completion is persisted so this is a one-time flow. */
function finish() {
  markOnboardingCompleted()
  router.replace('/')
}
</script>

<style scoped>
/* Language dropdown menu: subtle scale + fade. */
.lang-menu-enter-active {
  transition:
    opacity 0.18s ease-out,
    transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
.lang-menu-leave-active {
  transition:
    opacity 0.12s ease-in,
    transform 0.12s ease-in;
}
.lang-menu-enter-from,
.lang-menu-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.98);
}

/* Accessibility: neutralize all motion under the OS setting. */
@media (prefers-reduced-motion: reduce) {
  .lang-menu-enter-active,
  .lang-menu-leave-active {
    transition: none;
  }
}
</style>
