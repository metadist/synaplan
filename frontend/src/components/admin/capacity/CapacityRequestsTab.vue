<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityReportState from './CapacityReportState.vue'
import CapacitySlowRequest from './CapacitySlowRequest.vue'
import { useCapacityLabels } from '@/composables/useCapacityLabels'
import { useCapacityReport } from '@/composables/useCapacityReport'
import { capacityApi, type RouteClassRow } from '@/services/api/capacityApi'
import { formatDuration, perMinute, share, sortRows } from '@/utils/capacityTimeline'

/**
 * Admin › Capacity › Requests (AU33, journey J-AU-2): how long each kind of
 * request takes, and the slowest of the last hour opened up part by part.
 */
type SortKey = 'p95_ms' | 'p99_ms' | 'requests' | 'errors' | 'busy'

const { t } = useI18n()
const { label } = useCapacityLabels()
const { report, loading, unavailable, failed, load } = useCapacityReport(() =>
  capacityApi.requests()
)

const sortKey = ref<SortKey>('p95_ms')
const sortOptions: SortKey[] = ['p95_ms', 'p99_ms', 'requests', 'errors', 'busy']

const rows = computed<RouteClassRow[]>(() =>
  report.value ? sortRows(report.value.route_classes, sortKey.value, 'desc') : []
)
const total = computed(() => rows.value.reduce((acc, row) => acc + row.requests, 0))
const windowText = computed(() => formatDuration((report.value?.window_seconds ?? 0) * 1000))
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
    <div v-if="report" class="space-y-6" data-testid="capacity-requests">
      <div
        v-if="rows.length === 0"
        class="surface-card p-8 text-center txt-secondary"
        data-testid="state-capacity-requests-empty"
      >
        {{ t('capacity.requests.empty') }}
      </div>

      <section v-else class="surface-card p-4 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold txt-primary">{{ t('capacity.requests.title') }}</h2>
            <p class="text-sm txt-secondary" data-testid="text-capacity-requests-window">
              {{ t('capacity.requests.window', { count: total, window: windowText }, total) }}
            </p>
          </div>
          <label class="flex items-center gap-2 text-sm txt-secondary">
            {{ t('capacity.requests.sortBy') }}
            <select
              v-model="sortKey"
              class="px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="select-capacity-requests-sort"
            >
              <option v-for="key in sortOptions" :key="key" :value="key">
                {{ t(`capacity.requests.sort.${key}`) }}
              </option>
            </select>
          </label>
        </div>

        <div class="overflow-x-auto scroll-thin">
          <table class="w-full text-sm" data-testid="table-capacity-requests">
            <thead>
              <tr class="border-b border-light-border/30 dark:border-dark-border/20 text-left">
                <th class="py-2 pr-3 font-medium txt-secondary">
                  {{ t('capacity.requests.col.route') }}
                </th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">
                  {{ t('capacity.requests.col.requests') }}
                </th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">
                  {{ t('capacity.requests.col.perMinute') }}
                </th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">p50</th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">p95</th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">p99</th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">
                  {{ t('capacity.requests.col.errors') }}
                </th>
                <th class="py-2 px-3 font-medium txt-secondary text-right">
                  {{ t('capacity.requests.col.busy') }}
                </th>
                <th class="py-2 pl-3 font-medium txt-secondary text-right">
                  {{ t('capacity.requests.col.wait') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rows"
                :key="row.route_class"
                class="border-b border-light-border/20 dark:border-dark-border/10 last:border-0"
                data-testid="capacity-route-row"
                :data-route="row.route_class"
              >
                <td class="py-2 pr-3 txt-primary whitespace-nowrap">
                  {{ label('routeClass', row.route_class) }}
                </td>
                <td class="py-2 px-3 text-right tabular-nums txt-primary">{{ row.requests }}</td>
                <td class="py-2 px-3 text-right tabular-nums txt-secondary">
                  {{ perMinute(row.requests, report.window_seconds) }}
                </td>
                <td class="py-2 px-3 text-right tabular-nums txt-secondary">
                  {{ formatDuration(row.p50_ms) }}
                </td>
                <td
                  class="py-2 px-3 text-right tabular-nums txt-primary font-medium"
                  data-testid="capacity-route-p95"
                >
                  {{ formatDuration(row.p95_ms) }}
                </td>
                <td class="py-2 px-3 text-right tabular-nums txt-secondary">
                  {{ formatDuration(row.p99_ms) }}
                </td>
                <td class="py-2 px-3 text-right tabular-nums txt-secondary">{{ row.errors }}</td>
                <td class="py-2 px-3 text-right tabular-nums txt-secondary">
                  {{ share(row.busy, row.requests) }} %
                </td>
                <td
                  class="py-2 pl-3 text-right tabular-nums txt-secondary"
                  :title="
                    row.wait_p95_ms === null ? t('capacity.requests.waitNotMeasured') : undefined
                  "
                >
                  {{ formatDuration(row.wait_p95_ms) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="space-y-3" data-testid="section-capacity-slowest">
        <div class="px-2">
          <h2 class="text-lg font-semibold txt-primary">
            {{ t('capacity.requests.slowestTitle') }}
          </h2>
          <p class="text-sm txt-secondary">{{ t('capacity.requests.slowestHint') }}</p>
        </div>
        <p
          v-if="report.slowest.length === 0"
          class="surface-card p-6 text-center txt-secondary"
          data-testid="state-capacity-slowest-empty"
        >
          {{ t('capacity.requests.slowestEmpty') }}
        </p>
        <div v-else class="space-y-2">
          <CapacitySlowRequest
            v-for="(request, index) in report.slowest"
            :key="`${request.id}-${index}`"
            :request="request"
          />
        </div>
      </section>
    </div>
  </CapacityReportState>
</template>
