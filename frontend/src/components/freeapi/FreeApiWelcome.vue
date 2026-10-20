<template>
  <div class="flex-1 min-h-0 overflow-y-auto px-4 py-10" data-testid="freeapi-welcome">
    <div class="w-full max-w-2xl mx-auto">
      <div class="text-center mb-6">
        <div
          class="w-16 h-16 mx-auto mb-5 rounded-full bg-[var(--brand-alpha-light)] flex items-center justify-center"
          aria-hidden="true"
        >
          <ChatBubbleLeftRightIcon class="w-8 h-8 txt-brand" />
        </div>
        <h1 class="text-2xl font-semibold txt-primary mb-2">{{ $t('freeApi.welcome.title') }}</h1>
        <p class="txt-secondary text-base leading-relaxed">{{ $t('freeApi.welcome.lead') }}</p>
      </div>

      <FreeApiChoices v-model="choice" :ollama-badge="ollamaBadge" />

      <p
        v-if="choice === 'demo'"
        class="text-sm txt-secondary mt-4 leading-relaxed"
        data-testid="freeapi-consent"
      >
        {{
          $t('freeApi.consent', { server: freeApi.status?.server ?? $t('freeApi.serverFallback') })
        }}
      </p>
      <p
        v-if="errorKey"
        role="alert"
        class="text-sm text-red-600 dark:text-red-400 mt-3"
        data-testid="freeapi-start-error"
      >
        {{ $t(errorKey) }}
      </p>

      <div class="mt-6 flex justify-center">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="freeApi.busy"
          data-testid="freeapi-welcome-primary"
          @click="proceed"
        >
          {{ $t(freeApi.busy ? 'freeApi.welcome.starting' : `freeApi.welcome.primary.${choice}`) }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import FreeApiChoices from '@/components/freeapi/FreeApiChoices.vue'
import { useNotification } from '@/composables/useNotification'
import { useFreeApiStore } from '@/stores/freeApi'
import { useServiceReadinessStore } from '@/stores/serviceReadiness'
import { freeApiStartErrorKey, type FreeApiChoice } from '@/utils/freeApiCopy'
import { ollamaServiceBadge } from '@/utils/serviceReadiness'

/**
 * Replaces "No AI provider configured" for admins while the Synaplan Demo
 * module is on: the Demo, Ollama and an own key side by side, the Demo
 * preselected. The Demo starts only from here, after the consent sentence.
 */
const SETUP_SECTION: Record<Exclude<FreeApiChoice, 'demo'>, string> = {
  ollama: 'local-ai',
  key: 'providers',
}

const { t, locale } = useI18n()
const router = useRouter()
const { success } = useNotification()
const freeApi = useFreeApiStore()
const readinessStore = useServiceReadinessStore()

const choice = ref<FreeApiChoice>('demo')
const errorKey = ref('')

const ollamaBadge = computed(() =>
  ollamaServiceBadge(readinessStore.services, (key, params) => t(key, params ?? {}))
)

watch(choice, () => {
  errorKey.value = ''
})

onMounted(() => {
  void freeApi.load()
  readinessStore.acquire()
})

onUnmounted(() => {
  readinessStore.release()
})

async function proceed(): Promise<void> {
  if (choice.value !== 'demo') {
    await router.push({ path: '/admin/setup', query: { section: SETUP_SECTION[choice.value] } })
    return
  }
  errorKey.value = ''
  try {
    await freeApi.start(locale.value)
    success(t('freeApi.started'))
  } catch (err) {
    errorKey.value = freeApiStartErrorKey(err)
  }
}
</script>
