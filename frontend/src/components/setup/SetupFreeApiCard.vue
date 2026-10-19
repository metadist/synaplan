<template>
  <section class="surface-chip rounded-xl p-4 flex flex-col gap-3" data-testid="setup-freeapi-card">
    <div class="flex items-start gap-3">
      <SparklesIcon class="w-6 h-6 flex-shrink-0 mt-0.5 txt-brand" aria-hidden="true" />
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="text-base font-semibold txt-primary">{{ $t('freeApi.choice.demo.title') }}</h3>
          <span
            class="text-[11px] font-medium leading-none px-1.5 py-1 rounded-full bg-[var(--brand-alpha-light)] txt-brand"
          >
            {{ $t('freeApi.choice.demo.badge') }}
          </span>
        </div>
        <p class="text-sm txt-secondary mt-1">{{ $t('freeApi.choice.demo.text') }}</p>
      </div>
    </div>

    <p class="text-xs txt-secondary leading-relaxed" data-testid="setup-freeapi-consent">
      {{
        $t('freeApi.consent', { server: freeApi.status?.server ?? $t('freeApi.serverFallback') })
      }}
    </p>
    <p
      v-if="errorKey"
      role="alert"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="setup-freeapi-error"
    >
      {{ $t(errorKey) }}
    </p>

    <button
      type="button"
      class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="freeApi.busy"
      data-testid="setup-freeapi-start"
      @click="start"
    >
      {{ $t(freeApi.busy ? 'freeApi.welcome.starting' : 'freeApi.welcome.primary.demo') }}
    </button>
    <p class="text-xs txt-secondary text-center">{{ $t('freeApi.setupOrKey') }}</p>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import { useFreeApiStore } from '@/stores/freeApi'
import { freeApiStartErrorKey } from '@/utils/freeApiCopy'

/** The Synaplan Demo as the first option of the setup wizard's provider step. */
const emit = defineEmits<{ started: [] }>()

const { locale } = useI18n()
const freeApi = useFreeApiStore()
const errorKey = ref('')

onMounted(() => freeApi.load())

async function start(): Promise<void> {
  errorKey.value = ''
  try {
    await freeApi.start(locale.value)
    emit('started')
  } catch (err) {
    errorKey.value = freeApiStartErrorKey(err)
  }
}
</script>
