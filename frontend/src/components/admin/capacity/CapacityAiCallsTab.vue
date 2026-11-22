<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityReportState from './CapacityReportState.vue'
import { useCapacityLabels } from '@/composables/useCapacityLabels'
import { useCapacityReport } from '@/composables/useCapacityReport'
import { capacityApi } from '@/services/api/capacityApi'
import { formatCost, formatDuration, share } from '@/utils/capacityTimeline'

/**
 * Admin › Capacity › AI calls (AU33): which provider and model the recent
 * requests waited on, which part of Synaplan asked, and how much of each
 * call the pipeline hooks took.
 */
const { t } = useI18n()
const { label } = useCapacityLabels()
const { report, loading, unavailable, failed, load } = useCapacityReport(() =>
  capacityApi.aiCalls()
)

const totalCalls = computed(() =>
  (report.value?.sources ?? []).reduce((acc, row) => acc + row.calls, 0)
)

function hooksShare(hooksMs: number, durationMs: number): string {
  if (durationMs <= 0 || hooksMs <= 0) return formatDuration(hooksMs)
  const percent = share(hooksMs, durationMs)
  return `${formatDuration(hooksMs)} · ${percent < 1 ? '< 1' : percent} %`
}

const th = 'py-2 px-3 font-medium txt-secondary text-right whitespace-nowrap'
const td = 'py-2 px-3 text-right tabular-nums txt-secondary whitespace-nowrap'
</script>

<template>
  <CapacityReportState
    :loading="loading"
    :unavailable="unavailable"
    :failed="failed"
    :ready="report !== null"
    :loading-text="t('capacity.reports.loading')"
    :unavailable-text="t('capacity.reports.unavailable')"
    @retry="load"
  >
    <div v-if="report" class="space-y-6" data-testid="capacity-ai-calls">
      <div
        v-if="report.models.length === 0"
        class="surface-card p-8 text-center txt-secondary"
        data-testid="state-capacity-ai-empty"
      >
        {{ t('capacity.ai.empty') }}
      </div>

      <template v-else>
        <section class="surface-card p-4 space-y-3">
          <div>
            <h2 class="text-lg font-semibold txt-primary">{{ t('capacity.ai.modelsTitle') }}</h2>
            <p class="text-sm txt-secondary">
              {{ t('capacity.ai.modelsHint', { count: totalCalls }, totalCalls) }}
            </p>
          </div>
          <div class="overflow-x-auto scroll-thin">
            <table class="w-full text-sm" data-testid="table-capacity-ai-models">
              <thead>
                <tr class="border-b border-light-border/30 dark:border-dark-border/20 text-left">
                  <th class="py-2 pr-3 font-medium txt-secondary">
                    {{ t('capacity.ai.col.model') }}
                  </th>
                  <th :class="th">{{ t('capacity.ai.col.calls') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.failures') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.firstWord') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.duration') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.tokens') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.cost') }}</th>
                  <th :class="th" :title="t('capacity.ai.hooksHint')">
                    {{ t('capacity.ai.col.hooks') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in report.models"
                  :key="`${row.provider}/${row.model}/${row.capability}`"
                  class="border-b border-light-border/20 dark:border-dark-border/10 last:border-0"
                  data-testid="capacity-ai-model-row"
                  :data-model="`${row.provider}/${row.model}`"
                >
                  <td class="py-2 pr-3">
                    <div class="txt-primary">{{ row.provider }} · {{ row.model }}</div>
                    <div class="text-xs txt-secondary">
                      {{ label('capability', row.capability) }}
                    </div>
                  </td>
                  <td :class="td" class="txt-primary">{{ row.calls }}</td>
                  <td :class="td">{{ row.failures }}</td>
                  <td :class="td">
                    {{ formatDuration(row.ttft_p50_ms) }} / {{ formatDuration(row.ttft_p95_ms) }}
                  </td>
                  <td :class="td" class="font-medium">{{ formatDuration(row.duration_p95_ms) }}</td>
                  <td :class="td">{{ row.tokens_in }} / {{ row.tokens_out }}</td>
                  <td :class="td">{{ formatCost(row.cost) }}</td>
                  <td :class="td">{{ hooksShare(row.hooks_ms_p95, row.duration_p95_ms) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs txt-secondary">{{ t('capacity.ai.legend') }}</p>
        </section>

        <section class="surface-card p-4 space-y-3">
          <h2 class="text-lg font-semibold txt-primary">{{ t('capacity.ai.sourcesTitle') }}</h2>
          <div class="overflow-x-auto scroll-thin">
            <table class="w-full text-sm" data-testid="table-capacity-ai-sources">
              <thead>
                <tr class="border-b border-light-border/30 dark:border-dark-border/20 text-left">
                  <th class="py-2 pr-3 font-medium txt-secondary">
                    {{ t('capacity.ai.col.source') }}
                  </th>
                  <th :class="th">{{ t('capacity.ai.col.calls') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.share') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.failures') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.tokens') }}</th>
                  <th :class="th">{{ t('capacity.ai.col.cost') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in report.sources"
                  :key="row.source"
                  class="border-b border-light-border/20 dark:border-dark-border/10 last:border-0"
                  data-testid="capacity-ai-source-row"
                  :data-source="row.source"
                >
                  <td class="py-2 pr-3 txt-primary whitespace-nowrap">
                    {{ label('aiSource', row.source) }}
                  </td>
                  <td :class="td" class="txt-primary">{{ row.calls }}</td>
                  <td :class="td">{{ share(row.calls, totalCalls) }} %</td>
                  <td :class="td">{{ row.failures }}</td>
                  <td :class="td">{{ row.tokens_in }} / {{ row.tokens_out }}</td>
                  <td :class="td">{{ formatCost(row.cost) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>
    </div>
  </CapacityReportState>
</template>
