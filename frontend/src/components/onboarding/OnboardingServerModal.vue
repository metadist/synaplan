<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="modal-onboarding-server"
      >
        <div
          class="absolute inset-0 bg-black/40 backdrop-blur-sm"
          data-testid="modal-onboarding-server-backdrop"
          @click="emit('close')"
        ></div>

        <div
          class="relative surface-elevated max-w-md w-full p-6 rounded-2xl"
          role="dialog"
          aria-modal="true"
          :aria-label="$t('onboarding.welcome.pillServer')"
        >
          <div class="flex items-start gap-4">
            <div
              class="w-11 h-11 rounded-xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center flex-shrink-0"
            >
              <Icon icon="mdi:server-network" class="w-6 h-6 text-brand" />
            </div>
            <div class="min-w-0 flex-1">
              <h2 class="text-lg font-bold txt-primary">
                {{ $t('onboarding.server.customInfoTitle') }}
              </h2>
              <p class="text-sm txt-secondary mt-1 leading-relaxed">
                {{ $t('onboarding.server.customHint') }}
              </p>
            </div>
          </div>

          <!-- Currently connected server, so it's clear what will change. -->
          <p class="mt-4 text-xs txt-secondary">
            {{ $t('onboarding.server.defaultLabel') }}:
            <span class="font-semibold txt-primary" data-testid="text-server-current">{{
              displayHost
            }}</span>
          </p>

          <input
            v-model="customUrl"
            type="url"
            inputmode="url"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            class="onboarding-input surface-chip txt-primary placeholder:txt-secondary mt-3"
            :placeholder="$t('onboarding.server.customPlaceholder')"
            data-testid="input-server-url"
            @keydown.enter.prevent="connect"
          />

          <Transition name="error-slide">
            <p
              v-if="error"
              class="mt-2 text-xs text-red-500 dark:text-red-400"
              data-testid="text-server-error"
            >
              {{ error }}
            </p>
          </Transition>

          <a
            :href="SELF_HOST_DOCS_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-brand hover:underline underline-offset-2"
            data-testid="link-server-docs"
          >
            <Icon icon="mdi:book-open-variant-outline" class="w-4 h-4" aria-hidden="true" />
            {{ $t('onboarding.server.helpSetup') }}
          </a>

          <div class="mt-6 flex items-center gap-3">
            <button
              class="flex-1 py-2.5 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98]"
              data-testid="btn-server-cancel"
              @click="emit('close')"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              class="flex-1 py-2.5 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 active:scale-[0.98]"
              :disabled="connecting || !customUrl.trim()"
              data-testid="btn-server-connect"
              @click="connect"
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
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding): own-server modal.
 *
 * All server logic (normalize, probe, persist) stays app-owned behind the
 * `nativeServer.ts` seam. `saveNativeServerUrl()` only persists — it does not
 * reload the WebView, so this modal explicitly calls `reloadNativeApp()` once
 * the save succeeds. The onboarding resume step is written to page 1 BEFORE
 * saving and rolled back if the probe rejects the server. No sign-out step is
 * needed here (there is no session yet before onboarding completes).
 */
import { computed, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  getNativeServerUrl,
  getNativeDefaultServerUrl,
  saveNativeServerUrl,
  reloadNativeApp,
} from '@/services/api/nativeServer'
import { setOnboardingResumeStep, clearOnboardingResumeStep } from '@/composables/useOnboarding'

/** Public repo where the self-hosting / own-server setup guide lives. */
const SELF_HOST_DOCS_URL = 'https://github.com/metadist/synaplan'

const props = defineProps<{ isOpen: boolean }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const { t } = useI18n()

const customUrl = ref('')
const error = ref('')
const connecting = ref(false)

/** Host only (no scheme) — friendlier for a non-technical reader. */
const displayHost = computed(() => {
  const url = getNativeServerUrl() || getNativeDefaultServerUrl() || 'web.synaplan.com'
  return url.replace(/^https?:\/\//, '')
})

// Reset the transient state every time the modal opens.
watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      customUrl.value = ''
      error.value = ''
      connecting.value = false
    }
  }
)

async function connect() {
  const candidate = customUrl.value.trim()
  if ('' === candidate || connecting.value) {
    return
  }
  error.value = ''
  connecting.value = true
  // Remember to resume at page 1 BEFORE saving (the reload below is about to
  // happen), and roll it back if the probe rejects the server.
  setOnboardingResumeStep(1)
  try {
    const result = await saveNativeServerUrl(candidate)
    if (!result.ok) {
      clearOnboardingResumeStep()
      error.value = result.error || t('onboarding.server.connectError')
      return
    }
    emit('saved')
    reloadNativeApp()
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

.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-active .surface-elevated,
.modal-leave-active .surface-elevated {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}
.modal-enter-from .surface-elevated,
.modal-leave-to .surface-elevated {
  transform: scale(0.95) translateY(-10px);
  opacity: 0;
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

@media (prefers-reduced-motion: reduce) {
  .modal-enter-active,
  .modal-leave-active,
  .modal-enter-active .surface-elevated,
  .modal-leave-active .surface-elevated,
  .error-slide-enter-active,
  .error-slide-leave-active {
    transition: none;
  }
}
</style>
