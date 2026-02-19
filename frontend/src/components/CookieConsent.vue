<template>
  <Teleport to="#app">
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div
        v-if="showBanner"
        class="fixed bottom-0 left-0 right-0 z-[9999] p-4 md:p-6"
        data-testid="cookie-consent-banner"
      >
        <div
          class="max-w-4xl mx-auto surface-card border border-light-border dark:border-dark-border rounded-xl shadow-2xl p-6"
        >
          <div class="flex flex-col md:flex-row gap-4 md:items-center">
            <div class="flex-1">
              <h3 class="font-semibold txt-primary mb-2">{{ $t('cookies.title') }}</h3>
              <p class="text-sm txt-secondary">
                {{ $t('cookies.description') }}
                <a
                  href="https://www.synaplan.com/privacy-policy"
                  target="_blank"
                  class="text-[var(--brand)] hover:underline"
                >
                  {{ $t('cookies.privacyPolicy') }}
                </a>
              </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
              <button
                class="px-4 py-2 rounded-lg border border-light-border dark:border-dark-border bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors text-sm font-medium txt-primary"
                data-testid="btn-cookie-reject"
                @click="rejectAll"
              >
                {{ $t('cookies.rejectAll') }}
              </button>
              <button
                class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-cookie-accept"
                @click="acceptAll"
              >
                {{ $t('cookies.acceptAll') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getStoredConsent, storeConsent, type CookieConsent } from '@/composables/useCookieConsent'

const showBanner = ref(false)

const emit = defineEmits<{
  (e: 'consent', consent: CookieConsent): void
}>()

function acceptAll() {
  const consent = storeConsent(true)
  showBanner.value = false
  emit('consent', consent)
}

function rejectAll() {
  const consent = storeConsent(false)
  showBanner.value = false
  emit('consent', consent)
}

onMounted(() => {
  // Show banner if no consent stored or consent version outdated
  const consent = getStoredConsent()
  if (!consent) {
    showBanner.value = true
  } else {
    // Emit existing consent so parent can act on it
    emit('consent', consent)
  }
})
</script>
