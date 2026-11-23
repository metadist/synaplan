<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityReportState from './CapacityReportState.vue'
import ServerCapacityCard from './ServerCapacityCard.vue'
import { useCapacityReport } from '@/composables/useCapacityReport'
import { capacityApi } from '@/services/api/capacityApi'
import { groupServers, secondsSince } from '@/utils/capacityFacts'

/**
 * Admin › Capacity › Servers (AU7, journey J-AU-2): every server of this
 * installation, how loaded it is, and whether one needs help.
 */
const { t } = useI18n()

const { report, loading, unavailable, failed, load } = useCapacityReport(
  () => capacityApi.servers(),
  10_000
)
const now = ref(new Date())
let clock: ReturnType<typeof setInterval> | null = null

watch(report, () => {
  now.value = new Date()
})

onMounted(() => {
  clock = setInterval(() => {
    now.value = new Date()
  }, 10_000)
})

onUnmounted(() => {
  if (clock) clearInterval(clock)
})

const groups = computed(() => (report.value ? groupServers(report.value.servers) : []))
const connectedTo = computed(() => report.value?.served_by ?? null)
const updatedAgo = computed(() =>
  report.value ? (secondsSince(report.value.generated_at, now.value) ?? 0) : 0
)
</script>

<template>
  <CapacityReportState
    :loading="loading"
    :unavailable="unavailable"
    :failed="failed"
    :ready="report !== null"
    @retry="load"
  >
    <template v-if="report">
      <div class="flex items-center gap-3 px-2 flex-wrap" data-testid="section-capacity-summary">
        <h2 class="text-xl font-semibold txt-primary">{{ t('capacity.servers.title') }}</h2>
        <span class="text-sm txt-secondary" data-testid="text-capacity-summary">
          {{
            t(
              'capacity.servers.summary',
              { count: report.servers.length, seconds: updatedAgo },
              report.servers.length
            )
          }}
        </span>
        <span
          v-if="connectedTo && groups.length > 1"
          class="text-sm txt-secondary"
          data-testid="text-connected-to"
        >
          {{ t('capacity.servers.connectedTo', { server: connectedTo }) }}
        </span>
        <span class="text-xs txt-secondary" :title="t('capacity.servers.source')">{{
          t('capacity.servers.owner')
        }}</span>
        <div class="h-px flex-1 bg-[var(--divider)]"></div>
      </div>

      <div
        v-if="groups.length === 0"
        class="surface-card p-8 text-center space-y-4"
        data-testid="state-capacity-empty"
      >
        <p class="txt-primary">{{ t('capacity.empty') }}</p>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-capacity-retry"
          @click="load"
        >
          {{ t('capacity.checkAgain') }}
        </button>
      </div>

      <div
        v-else
        class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"
        data-testid="list-capacity-servers"
      >
        <ServerCapacityCard
          v-for="group in groups"
          :key="group.server.id"
          :server="group.server"
          :helpers="group.helpers"
          :connected="group.server.id === connectedTo"
          :now="now"
        />
      </div>
    </template>
  </CapacityReportState>
</template>
