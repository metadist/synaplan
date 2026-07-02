<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-server">
    <div class="onb-enter-1 mb-6 flex justify-center">
      <div
        class="w-14 h-14 rounded-2xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center"
      >
        <Icon icon="mdi:server-network" class="w-7 h-7 text-brand" />
      </div>
    </div>

    <h1 class="text-2xl font-bold txt-primary onb-enter-2">
      {{ $t('onboarding.server.title') }}
    </h1>
    <p class="text-sm txt-secondary mt-2.5 leading-relaxed onb-enter-3">
      {{ $t('onboarding.server.subtitle') }}
    </p>

    <!-- Connected / default server card -->
    <div
      class="mt-8 p-4 rounded-2xl surface-card ring-1 text-left flex items-center gap-4 onb-enter-4"
      :class="
        isOnCustomServer
          ? 'ring-black/[0.04] dark:ring-white/[0.05]'
          : 'ring-brand/30 dark:ring-brand/40'
      "
      data-testid="section-current-server"
    >
      <div
        class="w-10 h-10 rounded-xl bg-green-500/10 flex items-center justify-center flex-shrink-0"
      >
        <Icon icon="mdi:check-circle" class="w-5 h-5 text-green-500" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-xs txt-secondary">
          {{
            isOnCustomServer
              ? $t('onboarding.server.currentLabel')
              : $t('onboarding.server.defaultLabel')
          }}
        </p>
        <p class="text-sm font-semibold txt-primary truncate" data-testid="text-server-host">
          {{ displayHost }}
        </p>
      </div>
      <span
        v-if="!isOnCustomServer"
        class="text-[10px] font-semibold uppercase tracking-wide text-brand bg-brand/10 dark:bg-brand/20 px-2 py-1 rounded-md flex-shrink-0"
      >
        {{ $t('onboarding.server.recommended') }}
      </span>
    </div>

    <!-- Expert affordance: own server -->
    <div v-if="serverControlAvailable" class="mt-4 text-left onb-enter-5">
      <button
        class="text-xs font-medium text-brand hover:underline underline-offset-2 inline-flex items-center gap-1"
        data-testid="btn-toggle-custom-server"
        @click="showCustom = !showCustom"
      >
        <Icon
          :icon="showCustom ? 'mdi:chevron-up' : 'mdi:chevron-down'"
          class="w-4 h-4 transition-transform duration-200"
        />
        {{ $t('onboarding.server.customToggle') }}
      </button>

      <Transition name="expand">
        <div v-if="showCustom" class="mt-3 space-y-3" data-testid="section-custom-server">
          <p class="text-xs txt-secondary leading-relaxed">
            {{ $t('onboarding.server.customHint') }}
          </p>
          <input
            v-model="customUrl"
            type="url"
            inputmode="url"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            class="onboarding-input surface-chip txt-primary placeholder:txt-secondary"
            :placeholder="$t('onboarding.server.customPlaceholder')"
            data-testid="input-custom-server"
            @keydown.enter.prevent="connectCustom"
          />
          <Transition name="error-slide">
            <p
              v-if="customError"
              class="text-xs text-red-500 dark:text-red-400"
              data-testid="text-server-error"
            >
              {{ customError }}
            </p>
          </Transition>
          <button
            class="w-full py-2.5 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98]"
            :disabled="connecting || !customUrl.trim()"
            data-testid="btn-connect-custom"
            @click="connectCustom"
          >
            <span v-if="connecting" class="inline-flex items-center gap-2">
              <span
                class="w-3.5 h-3.5 border-2 border-current/30 border-t-current rounded-full animate-spin"
              />
              {{ $t('onboarding.server.connecting') }}
            </span>
            <span v-else>{{ $t('onboarding.server.connect') }}</span>
          </button>
        </div>
      </Transition>
    </div>

    <button
      class="mt-8 w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98] onb-enter-6"
      data-testid="btn-server-next"
      @click="emit('next')"
    >
      {{ $t('onboarding.next') }}
    </button>
    <button
      class="mt-3 w-full py-2 text-sm font-medium txt-secondary hover:txt-primary transition-colors onb-enter-6"
      data-testid="btn-server-back"
      @click="emit('back')"
    >
      {{ $t('onboarding.back') }}
    </button>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), step 2: server selection.
 *
 * The default server is preselected — continuing is a single tap. Pointing the
 * app at an own Synaplan server is an expert affordance behind a collapsed
 * toggle. All server logic (normalize, probe, persist, reload) stays app-owned
 * behind the `nativeServer.ts` seam; a successful save reloads the WebView, so
 * the resume step is written BEFORE saving and the flow continues at step 3
 * after the reload (see `useOnboarding.ts`).
 */
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  isNativeServerControlAvailable,
  getNativeServerUrl,
  getNativeDefaultServerUrl,
  saveNativeServerUrl,
} from '@/services/api/nativeServer'
import { setOnboardingResumeStep, clearOnboardingResumeStep } from '@/composables/useOnboarding'

const emit = defineEmits<{ next: []; back: [] }>()

const { t } = useI18n()

const serverControlAvailable = isNativeServerControlAvailable()
const currentUrl = getNativeServerUrl()
const defaultUrl = getNativeDefaultServerUrl()

const isOnCustomServer = computed(
  () => '' !== currentUrl && '' !== defaultUrl && currentUrl !== defaultUrl
)

/** Host only (no scheme) — friendlier for a non-technical reader. */
const displayHost = computed(() => {
  const url = currentUrl || defaultUrl || 'web.synaplan.com'
  return url.replace(/^https?:\/\//, '')
})

const showCustom = ref(false)
const customUrl = ref('')
const customError = ref('')
const connecting = ref(false)

async function connectCustom() {
  const candidate = customUrl.value.trim()
  if ('' === candidate || connecting.value) {
    return
  }
  customError.value = ''
  connecting.value = true
  // A successful save reloads the WebView immediately — remember where to
  // resume BEFORE saving, and roll it back if the probe rejects the server.
  setOnboardingResumeStep(3)
  try {
    const result = await saveNativeServerUrl(candidate)
    if (!result.ok) {
      clearOnboardingResumeStep()
      customError.value = result.error || t('onboarding.server.connectError')
      return
    }
    // Reload is imminent; nothing else to do. If the shell ever resolves
    // without reloading, moving on is still the right outcome.
    emit('next')
  } finally {
    connecting.value = false
  }
}
</script>

<style scoped>
.onboarding-input {
  width: 100%;
  padding: 0.75rem 1rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  border: 0;
  transition: all 0.2s;
}
.onboarding-input:focus {
  outline: none;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 40%, transparent);
}

/* Expand/collapse for the expert section */
.expand-enter-active {
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.expand-leave-active {
  transition: all 0.15s ease-in;
}
.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

.error-slide-enter-active {
  transition: all 0.2s ease-out;
}
.error-slide-leave-active {
  transition: all 0.15s ease-in;
}
.error-slide-enter-from,
.error-slide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* Staggered enter (same family as the auth pages). */
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
  .onb-enter-6 {
    animation: none;
  }
  .expand-enter-active,
  .expand-leave-active,
  .error-slide-enter-active,
  .error-slide-leave-active {
    transition: none;
  }
}
</style>
