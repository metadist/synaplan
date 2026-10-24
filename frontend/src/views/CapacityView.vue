<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-capacity"
    >
      <div class="max-w-[100rem] mx-auto space-y-6">
        <PageHeader
          :title="t('capacity.title')"
          :subtitle="t('capacity.subtitle')"
          icon="mdi:server-network"
        />

        <div
          v-if="loading && !report"
          class="surface-card p-8 text-center"
          data-testid="state-capacity-loading"
        >
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto mb-4 txt-secondary" />
          <div class="txt-secondary">{{ t('capacity.loading') }}</div>
        </div>

        <div
          v-else-if="unavailable"
          class="surface-card p-8 text-center space-y-4"
          data-testid="state-capacity-unavailable"
        >
          <p class="txt-primary">{{ t('capacity.unavailable') }}</p>
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
          v-else-if="failed"
          class="surface-card p-8 text-center space-y-4"
          data-testid="state-capacity-error"
        >
          <p class="txt-primary">{{ t('capacity.failed') }}</p>
          <button
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-capacity-retry"
            @click="load"
          >
            {{ t('capacity.checkAgain') }}
          </button>
        </div>

        <template v-else-if="report">
          <div
            class="flex items-center gap-3 px-2 flex-wrap"
            data-testid="section-capacity-summary"
          >
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

          <p class="px-2 text-xs txt-secondary">{{ t('capacity.footer') }}</p>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ServerCapacityCard from '@/components/admin/capacity/ServerCapacityCard.vue'
import { capacityApi, type CapacityReport } from '@/services/api/capacityApi'
import { ApiError } from '@/services/api/httpClient'
import { groupServers, secondsSince } from '@/utils/capacityFacts'

/**
 * Admin › System › Capacity (architecture update AU7, journey J-AU-2): every
 * server of this installation, how loaded it is, and whether one needs help.
 * Reads one endpoint every 10 seconds; nothing on this page changes a server.
 */
const { t } = useI18n()

const report = ref<CapacityReport | null>(null)
const loading = ref(false)
const unavailable = ref(false)
const failed = ref(false)
const now = ref(new Date())

const POLL_MS = 10_000
let timer: ReturnType<typeof setInterval> | null = null

const groups = computed(() => (report.value ? groupServers(report.value.servers) : []))
const connectedTo = computed(() => report.value?.served_by ?? null)
const updatedAgo = computed(() =>
  report.value ? (secondsSince(report.value.generated_at, now.value) ?? 0) : 0
)

async function load(): Promise<void> {
  loading.value = true
  try {
    report.value = await capacityApi.servers()
    unavailable.value = false
    failed.value = false
    now.value = new Date()
  } catch (error) {
    if (error instanceof ApiError && error.status === 503) {
      unavailable.value = true
    } else {
      failed.value = true
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
  timer = setInterval(() => {
    now.value = new Date()
    void load()
  }, POLL_MS)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>
