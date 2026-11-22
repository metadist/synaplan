<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityReportState from './CapacityReportState.vue'
import { useCapacityReport } from '@/composables/useCapacityReport'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { capacityApi, type OutboundRow } from '@/services/api/capacityApi'
import { ApiError } from '@/services/api/httpClient'
import { formatDuration } from '@/utils/capacityTimeline'

/**
 * Admin › Capacity › Outbound (AU33, journey J-AU-3): where Synaplan called
 * out to, and which of those the outbound filter does not know. Allow adds
 * the destination to the allow list; Remove takes it off again after asking.
 */
const { t } = useI18n()
const { success, error } = useNotification()
const dialog = useDialog()
const { report, loading, unavailable, failed, load, refresh } = useCapacityReport(() =>
  capacityApi.outbound()
)

const saving = ref<string | null>(null)
const mode = computed(() => report.value?.mode ?? 'off')

function stateText(row: OutboundRow): string {
  if (row.state === 'not_listed') {
    if (mode.value === 'report') return t('capacity.outbound.state.wouldBeRefused')
    if (mode.value === 'enforce') return t('capacity.outbound.state.refused')
    return t('capacity.outbound.state.notListed')
  }
  if (row.state === 'listed') return t('capacity.outbound.state.listed', { rule: row.rule ?? '' })
  return t(`capacity.outbound.state.${row.state}`)
}

function destinationText(row: OutboundRow): string {
  return row.state === 'typed' ? t('capacity.outbound.typed') : row.destination
}

function failMessage(err: unknown): string {
  return err instanceof ApiError && err.status === 422
    ? t('capacity.outbound.invalidRule')
    : t('capacity.outbound.saveFailed')
}

async function allow(row: OutboundRow): Promise<void> {
  const rule = row.allow_rule
  if (!rule || saving.value) return
  saving.value = row.destination
  try {
    await capacityApi.allow(rule)
    row.state = 'listed'
    row.rule = rule
    success(t('capacity.outbound.allowed', { rule }))
    void refresh()
  } catch (err) {
    error(failMessage(err))
  } finally {
    saving.value = null
  }
}

async function remove(row: OutboundRow): Promise<void> {
  const rule = row.rule
  if (!rule || saving.value) return
  const enforcing = mode.value === 'enforce'
  const confirmed = await dialog.confirm({
    title: t('capacity.outbound.removeTitle'),
    message: t(enforcing ? 'capacity.outbound.removeEnforce' : 'capacity.outbound.removeReport', {
      rule,
    }),
    confirmText: t('capacity.outbound.remove'),
    cancelText: t('common.cancel'),
    danger: enforcing,
  })
  if (!confirmed) return
  saving.value = row.destination
  try {
    await capacityApi.removeAllow(rule)
    row.state = 'not_listed'
    row.rule = null
    row.allow_rule = rule
    success(t('capacity.outbound.removed', { rule }))
    void refresh()
  } catch (err) {
    error(failMessage(err))
  } finally {
    saving.value = null
  }
}

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
    <div v-if="report" class="space-y-4" data-testid="capacity-outbound" :data-mode="report.mode">
      <div
        class="surface-card p-4 flex flex-wrap items-center gap-3"
        data-testid="capacity-outbound-mode"
      >
        <p class="min-w-0 flex-1 text-sm txt-primary">
          {{ t(`capacity.outbound.mode.${report.mode}`) }}
        </p>
        <router-link
          to="/admin/config?tab=more_outbound&section=policy&highlight=EGRESS_MODE"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="link-capacity-outbound-setting"
        >
          {{ t('capacity.outbound.openSetting') }}
        </router-link>
      </div>

      <div
        v-if="report.destinations.length === 0"
        class="surface-card p-8 text-center txt-secondary"
        data-testid="state-capacity-outbound-empty"
      >
        {{ report.mode === 'off' ? t('capacity.outbound.emptyOff') : t('capacity.outbound.empty') }}
      </div>

      <section v-else class="surface-card p-4 space-y-3">
        <div>
          <h2 class="text-lg font-semibold txt-primary">{{ t('capacity.outbound.title') }}</h2>
          <p class="text-sm txt-secondary">{{ t('capacity.outbound.hint') }}</p>
        </div>
        <div class="overflow-x-auto scroll-thin">
          <table class="w-full text-sm" data-testid="table-capacity-outbound">
            <thead>
              <tr class="border-b border-light-border/30 dark:border-dark-border/20 text-left">
                <th class="py-2 pr-3 font-medium txt-secondary">
                  {{ t('capacity.outbound.col.destination') }}
                </th>
                <th :class="th">{{ t('capacity.outbound.col.requests') }}</th>
                <th :class="th">{{ t('capacity.outbound.col.failures') }}</th>
                <th :class="th">{{ t('capacity.outbound.col.refused') }}</th>
                <th :class="th">p95</th>
                <th class="py-2 px-3 font-medium txt-secondary">
                  {{ t('capacity.outbound.col.state') }}
                </th>
                <th class="py-2 pl-3">
                  <span class="sr-only">{{ t('capacity.outbound.col.action') }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in report.destinations"
                :key="row.destination"
                class="border-b border-light-border/20 dark:border-dark-border/10 last:border-0"
                data-testid="capacity-outbound-row"
                :data-destination="row.destination"
                :data-state="row.state"
              >
                <td class="py-2 pr-3 txt-primary break-all">{{ destinationText(row) }}</td>
                <td :class="td" class="txt-primary">{{ row.requests }}</td>
                <td :class="td">{{ row.failures }}</td>
                <td :class="td">{{ row.refused }}</td>
                <td :class="td">{{ formatDuration(row.p95_ms) }}</td>
                <td
                  class="py-2 px-3"
                  :class="
                    row.state === 'not_listed' && report.mode !== 'off'
                      ? 'text-sm text-red-600 dark:text-red-400'
                      : 'txt-secondary'
                  "
                  data-testid="capacity-outbound-state"
                >
                  {{ stateText(row) }}
                </td>
                <td class="py-2 pl-3 text-right whitespace-nowrap">
                  <button
                    v-if="row.state === 'not_listed' && row.allow_rule"
                    type="button"
                    class="btn-primary px-4 py-2.5 text-sm font-medium"
                    :disabled="saving !== null"
                    :aria-label="t('capacity.outbound.allowLabel', { rule: row.allow_rule })"
                    data-testid="btn-capacity-outbound-allow"
                    @click="allow(row)"
                  >
                    {{ t('capacity.outbound.allow') }}
                  </button>
                  <button
                    v-else-if="row.state === 'listed' && row.rule"
                    type="button"
                    class="btn-secondary px-4 py-2.5 text-sm font-medium"
                    :disabled="saving !== null"
                    :aria-label="t('capacity.outbound.removeLabel', { rule: row.rule })"
                    data-testid="btn-capacity-outbound-remove"
                    @click="remove(row)"
                  >
                    {{ t('capacity.outbound.remove') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </CapacityReportState>
</template>
