<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityReportState from './CapacityReportState.vue'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'
import { useCapacityLabels } from '@/composables/useCapacityLabels'
import { useCapacityReport } from '@/composables/useCapacityReport'
import { capacityApi } from '@/services/api/capacityApi'
import { useApprovalsStore } from '@/stores/approvals'
import { formatDuration } from '@/utils/capacityTimeline'

/**
 * Admin › Capacity › Tools (AU33): how tool calls of the recent requests
 * ended, per source and kind. The table counts calls that asked for
 * approval in the window; the Approvals link counts what still waits for
 * this administrator. This tab never shows which tool or what it was asked.
 */
const { t } = useI18n()
const { label } = useCapacityLabels()
const { report, loading, unavailable, failed, load } = useCapacityReport(() => capacityApi.tools())

const approvals = useApprovalsStore()
const waiting = computed(() => approvals.pendingCount)
const approvalsOn = computed(() => isApprovalsEnabled())

onMounted(() => {
  if (approvalsOn.value) void approvals.load('pending')
})

const th = 'py-2 px-3 font-medium txt-secondary text-right whitespace-nowrap'
const td = 'py-2 px-3 text-right tabular-nums txt-secondary'
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
    <div v-if="report" class="space-y-4" data-testid="capacity-tools">
      <div
        v-if="report.tools.length === 0"
        class="surface-card p-8 text-center txt-secondary"
        data-testid="state-capacity-tools-empty"
      >
        {{ t('capacity.tools.empty') }}
      </div>

      <section v-else class="surface-card p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold txt-primary">{{ t('capacity.tools.title') }}</h2>
            <p class="text-sm txt-secondary">{{ t('capacity.tools.hint') }}</p>
          </div>
          <router-link
            v-if="waiting > 0 && approvalsOn"
            to="/approvals"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="link-capacity-approvals"
          >
            {{ t('capacity.tools.openApprovals', { count: waiting }, waiting) }}
          </router-link>
        </div>
        <div class="overflow-x-auto scroll-thin">
          <table class="w-full text-sm" data-testid="table-capacity-tools">
            <thead>
              <tr class="border-b border-light-border/30 dark:border-dark-border/20 text-left">
                <th class="py-2 pr-3 font-medium txt-secondary">
                  {{ t('capacity.tools.col.source') }}
                </th>
                <th class="py-2 px-3 font-medium txt-secondary">
                  {{ t('capacity.tools.col.kind') }}
                </th>
                <th :class="th">{{ t('capacity.tools.col.calls') }}</th>
                <th :class="th">{{ t('capacity.toolOutcome.done') }}</th>
                <th :class="th">{{ t('capacity.toolOutcome.refused') }}</th>
                <th :class="th">{{ t('capacity.tools.col.askedApproval') }}</th>
                <th :class="th">{{ t('capacity.toolOutcome.failed') }}</th>
                <th :class="th">p95</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in report.tools"
                :key="`${row.source}/${row.kind}`"
                class="border-b border-light-border/20 dark:border-dark-border/10 last:border-0"
                data-testid="capacity-tool-row"
                :data-source="row.source"
                :data-kind="row.kind"
              >
                <td class="py-2 pr-3 txt-primary whitespace-nowrap">
                  {{ label('toolSource', row.source) }}
                </td>
                <td class="py-2 px-3 txt-secondary whitespace-nowrap">
                  {{ label('toolKind', row.kind) }}
                </td>
                <td :class="td" class="txt-primary">{{ row.calls }}</td>
                <td :class="td">{{ row.done }}</td>
                <td :class="td">{{ row.refused }}</td>
                <td :class="td" data-testid="capacity-tool-waiting">{{ row.waiting }}</td>
                <td :class="td">{{ row.failed }}</td>
                <td :class="td">{{ formatDuration(row.p95_ms) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs txt-secondary">{{ t('capacity.tools.failedHint') }}</p>
      </section>
    </div>
  </CapacityReportState>
</template>
