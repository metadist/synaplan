<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import { useConfigStore } from '@/stores/config'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import type { UsageStats } from '@/api/usageApi'

const props = defineProps<{
  budget: UsageStats['cost_budget']
}>()

const { t } = useI18n()
const { formatDate: formatDateStr } = useDateFormat()
const config = useConfigStore()
const topupLoading = ref(false)
const topupError = ref<string | null>(null)

const showTopup = computed(
  () => !isNativeApp() && config.billing.enabled && props.budget.budget > 0
)

const formatDate = (timestamp: number) => formatDateStr(new Date(timestamp * 1000))

const barClass = (percent: number) => {
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 75) return 'bg-orange-500'
  if (percent >= 50) return 'bg-yellow-500'
  return 'bg-green-500'
}

async function startTopup(): Promise<void> {
  if (topupLoading.value) return
  topupLoading.value = true
  topupError.value = null
  try {
    const session = await subscriptionApi.createTopupSession(1)
    if (session.url) {
      window.location.href = session.url
      return
    }
    topupError.value = t('limitReached.topup.error')
  } catch {
    topupError.value = t('limitReached.topup.error')
  } finally {
    topupLoading.value = false
  }
}
</script>

<template>
  <div class="surface-card p-6" data-testid="section-cost-budget">
    <h3 class="text-lg font-semibold txt-primary mb-4">
      {{ t('config.usage.costBudget.title') }}
    </h3>

    <div v-if="budget.budget > 0" class="space-y-3">
      <div class="flex items-center justify-between text-sm">
        <span class="txt-primary font-medium">
          {{ t('config.usage.costBudget.consumption') }}: {{ budget.percent.toFixed(1) }}%
        </span>
      </div>

      <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
        <div
          class="h-4 rounded-full transition-all flex items-center justify-center text-xs text-white font-medium"
          :class="barClass(budget.percent)"
          :style="{ width: Math.min(Math.max(budget.percent, 2), 100) + '%' }"
        >
          <span v-if="budget.percent > 15">{{ budget.percent.toFixed(0) }}%</span>
        </div>
      </div>

      <div class="text-xs txt-secondary text-left">
        {{ formatDate(budget.period_start) }} - {{ formatDate(budget.period_end) }}
      </div>

      <div v-if="showTopup" class="pt-2 space-y-3">
        <p class="text-sm txt-secondary">
          {{ t('limitReached.topup.sentence', { amount: 100 }) }}
        </p>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="topupLoading"
          data-testid="btn-usage-topup"
          @click="startTopup"
        >
          {{ t('limitReached.topup.button', { amount: 100 }) }}
        </button>
        <p v-if="topupError" class="text-sm text-red-600 dark:text-red-400">{{ topupError }}</p>
      </div>
    </div>

    <div v-else class="space-y-3">
      <div class="flex items-center justify-between text-sm">
        <span class="txt-primary font-medium">
          {{ t('config.usage.costBudget.thisMonth') }}
        </span>
        <span class="txt-secondary"> ${{ budget.used.toFixed(4) }} </span>
      </div>

      <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
        <div
          class="h-4 rounded-full transition-all bg-green-500 flex items-center justify-center text-xs text-white font-medium"
          style="width: 100%"
        >
          {{ t('config.usage.costBudget.unlimited') }}
        </div>
      </div>

      <div class="text-xs txt-secondary">
        {{ formatDate(budget.period_start) }} - {{ formatDate(budget.period_end) }}
      </div>
    </div>
  </div>
</template>
