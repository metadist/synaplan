<template>
  <section class="surface-card rounded-lg p-4" data-testid="research-admin-sample-card">
    <h2 class="text-lg font-semibold txt-primary">
      {{ t('adminSetup.research.sampleTitle') }}
    </h2>
    <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.sampleHint') }}</p>

    <p
      v-if="current?.status === 'running'"
      class="text-sm txt-primary mt-3 inline-flex items-center gap-2"
      data-testid="research-admin-sample-running"
      role="status"
    >
      <Icon icon="mdi:loading" class="w-4 h-4 animate-spin" aria-hidden="true" />
      {{ t('adminSetup.research.sampleRunning') }}
    </p>
    <p
      v-else-if="current?.status === 'done' && current.sentence"
      class="text-sm txt-primary mt-3"
      data-testid="research-admin-sample-result"
      role="status"
    >
      {{ t('adminSetup.research.sampleDone', { sentence: current.sentence }) }}
    </p>

    <template v-if="current?.status !== 'running'">
      <p v-if="!enabled" class="text-sm txt-secondary mt-4">
        {{ t('adminSetup.research.sampleNeedsOn') }}
      </p>
      <p
        v-else-if="dirty"
        class="text-sm txt-secondary mt-4"
        data-testid="research-admin-sample-dirty"
      >
        {{ t('adminSetup.research.sampleNeedsSave') }}
      </p>
      <label class="mt-4 flex items-start gap-2 text-sm txt-primary">
        <input
          v-model="accepted"
          type="checkbox"
          class="mt-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!enabled || dirty"
          data-testid="research-admin-sample-confirm"
        />
        <span>{{ t('adminSetup.research.sampleConfirm', { amount: formattedCap }) }}</span>
      </label>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium mt-3 disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="research-admin-sample"
        :disabled="!enabled || dirty || !accepted || starting"
        @click="start"
      >
        {{ t('adminSetup.research.sampleButton') }}
      </button>
    </template>

    <p v-if="errorText" class="text-sm text-red-600 dark:text-red-400 mt-3" role="alert">
      {{ errorText }}
    </p>
  </section>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  fetchResearchAdmin,
  startResearchSample,
  type ResearchAdmin,
} from '@/services/api/researchAdminApi'
import { formatUsd } from '@/utils/usageFormat'
import { REFUSAL_KEYS, researchRefusal } from './researchAdminErrors'

type Sample = ResearchAdmin['sample']

const POLL_MS = 5000

const props = defineProps<{
  enabled: boolean
  dirty: boolean
  quickCostCap: number
  sample: Sample
}>()

const { t, locale } = useI18n()
const current = ref<Sample>(props.sample)
const accepted = ref(false)
const starting = ref(false)
const errorText = ref('')
let pollTimer: ReturnType<typeof setTimeout> | null = null

const formattedCap = computed(() => formatUsd(props.quickCostCap, locale.value))

function stopPolling(): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

function schedulePoll(): void {
  stopPolling()
  if (current.value?.status !== 'running') return
  pollTimer = setTimeout(async () => {
    pollTimer = null
    try {
      current.value = (await fetchResearchAdmin()).sample
    } catch {
      // A missed poll is retried on the next tick; the run itself is unaffected.
    }
    schedulePoll()
  }, POLL_MS)
}

watch(
  () => props.sample,
  (next) => {
    current.value = next
    schedulePoll()
  },
  { immediate: true }
)

watch(
  () => [props.quickCostCap, props.enabled, props.dirty],
  () => {
    accepted.value = false
  }
)

async function start(): Promise<void> {
  starting.value = true
  errorText.value = ''
  try {
    const started = await startResearchSample()
    current.value = { id: started.id, status: 'running', sentence: null }
    accepted.value = false
    schedulePoll()
  } catch (error) {
    const refusal = researchRefusal(error)
    const key = refusal ? REFUSAL_KEYS[refusal.code] : undefined
    errorText.value = key ? t(key) : t('adminSetup.research.errors.sampleFailed')
  } finally {
    starting.value = false
  }
}

onUnmounted(stopPolling)
</script>
