<template>
  <div class="surface-card p-6" data-testid="section-embedding-runs">
    <div class="flex items-start justify-between gap-4 mb-5">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-[var(--brand)]/10 flex items-center justify-center">
          <ArrowPathIcon class="w-5 h-5 text-[var(--brand)]" />
        </div>
        <div>
          <h2 class="text-xl font-semibold txt-primary">
            {{ $t('config.embeddingRuns.title') }}
          </h2>
          <p class="text-sm txt-secondary">{{ $t('config.embeddingRuns.subtitle') }}</p>
        </div>
      </div>
      <button
        type="button"
        class="text-sm txt-secondary hover:txt-primary transition-colors flex items-center gap-1"
        :disabled="loading"
        data-testid="btn-refresh-runs"
        @click="loadRuns()"
      >
        <ArrowPathIcon class="w-4 h-4" :class="loading && 'animate-spin'" />
        {{ $t('config.embeddingRuns.refresh') }}
      </button>
    </div>

    <!-- Active Run Live-Progress Card -->
    <div
      v-if="activeRun"
      class="mb-5 rounded-xl border-2 p-4 animate-pulse-soft"
      :class="severityBannerClasses(activeRun.severity)"
      data-testid="card-active-run"
    >
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <span class="relative inline-flex w-3 h-3">
            <span
              class="absolute inline-flex w-full h-full rounded-full opacity-75 animate-ping"
              :class="statusDotColor(activeRun.status)"
            ></span>
            <span
              class="relative inline-flex w-3 h-3 rounded-full"
              :class="statusDotColor(activeRun.status)"
            ></span>
          </span>
          <span class="font-semibold txt-primary">
            {{ $t(`config.embeddingRuns.status.${activeRun.status}`) }}
          </span>
          <span class="text-xs txt-secondary">·</span>
          <span class="text-sm txt-secondary capitalize">
            {{ $t(`config.embeddingSwitch.scopes.${activeRun.scope}`) }}
          </span>
        </div>
        <span class="text-xs txt-secondary font-mono">#{{ activeRun.id }}</span>
      </div>

      <!-- Progress Bar -->
      <div class="mb-2">
        <div class="flex items-center justify-between text-xs mb-1.5">
          <span class="txt-secondary">
            {{ formatNumber(activeRun.chunksProcessed) }} /
            {{ formatNumber(activeRun.chunksTotal ?? 0) }} {{ $t('config.embeddingRuns.chunks') }}
          </span>
          <span class="txt-secondary"
            >~{{ formatTokens(activeRun.tokensProcessed) }}
            {{ $t('config.embeddingRuns.tokens') }}</span
          >
        </div>
        <div class="w-full h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden">
          <div
            class="h-full rounded-full transition-all duration-500"
            :class="progressBarColor(activeRun.severity)"
            :style="{ width: `${progressPercent(activeRun)}%` }"
          ></div>
        </div>
      </div>

      <p class="text-xs txt-secondary mt-2">{{ $t('config.embeddingRuns.runningHint') }}</p>
    </div>

    <!-- History Table -->
    <div
      v-if="loading && runs.length === 0"
      class="text-center py-8"
      data-testid="section-runs-loading"
    >
      <div
        class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-[var(--brand)]"
      ></div>
    </div>

    <div
      v-else-if="runs.length === 0"
      class="text-center py-10 rounded-xl border-2 border-dashed border-light-border/30 dark:border-dark-border/20"
      data-testid="section-runs-empty"
    >
      <CheckCircleIcon class="w-10 h-10 txt-secondary mx-auto mb-2 opacity-50" />
      <p class="txt-secondary text-sm">{{ $t('config.embeddingRuns.empty') }}</p>
    </div>

    <div v-else class="overflow-x-auto scroll-thin">
      <table class="w-full min-w-[700px] text-sm" data-testid="table-runs">
        <thead>
          <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
            <th class="text-left py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.when') }}
            </th>
            <th class="text-left py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.scope') }}
            </th>
            <th class="text-left py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.status') }}
            </th>
            <th class="text-right py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.chunks') }}
            </th>
            <th class="text-right py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.tokens') }}
            </th>
            <th class="text-right py-2 px-2 txt-secondary text-xs uppercase tracking-wide">
              {{ $t('config.embeddingRuns.col.cost') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="run in runs"
            :key="run.id"
            class="border-b border-light-border/20 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            data-testid="row-run"
          >
            <td class="py-2.5 px-2 txt-primary">
              <div class="flex flex-col">
                <span class="text-sm">{{ formatDate(run.created) }}</span>
                <span class="text-xs txt-secondary font-mono">#{{ run.id }}</span>
              </div>
            </td>
            <td class="py-2.5 px-2 txt-secondary capitalize">
              {{ $t(`config.embeddingSwitch.scopes.${run.scope}`) }}
            </td>
            <td class="py-2.5 px-2">
              <span
                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-semibold"
                :class="statusBadgeClass(run.status)"
              >
                <span class="w-1.5 h-1.5 rounded-full" :class="statusDotColor(run.status)"></span>
                {{ $t(`config.embeddingRuns.status.${run.status}`) }}
              </span>
            </td>
            <td class="py-2.5 px-2 text-right txt-primary font-mono text-xs">
              {{ formatNumber(run.chunksProcessed) }}
              <span v-if="run.chunksFailed > 0" class="text-red-500">/-{{ run.chunksFailed }}</span>
            </td>
            <td class="py-2.5 px-2 text-right txt-primary font-mono text-xs">
              ~{{ formatTokens(run.tokensProcessed) }}
            </td>
            <td class="py-2.5 px-2 text-right txt-brand font-mono text-xs font-semibold">
              ${{ Number(run.costEstimatedUsd ?? 0).toFixed(4) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, computed } from 'vue'
import { ArrowPathIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import {
  adminEmbeddingApi,
  type EmbeddingRun,
  type EmbeddingSeverity,
} from '@/services/api/adminEmbeddingApi'

const runs = ref<EmbeddingRun[]>([])
const loading = ref(false)
const pollHandle = ref<number | null>(null)

const activeRun = computed<EmbeddingRun | null>(() => {
  return runs.value.find((r) => r.status === 'queued' || r.status === 'running') ?? null
})

const formatDate = (unixSeconds: number): string => {
  const d = new Date(unixSeconds * 1000)
  return d.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const formatNumber = (n: number): string => n.toLocaleString()

const formatTokens = (n: number): string => {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(2)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}k`
  return String(n)
}

const progressPercent = (run: EmbeddingRun): number => {
  if (!run.chunksTotal || run.chunksTotal === 0) return 0
  return Math.min(100, Math.round((run.chunksProcessed / run.chunksTotal) * 100))
}

const statusDotColor = (status: string): string => {
  switch (status) {
    case 'running':
      return 'bg-emerald-500'
    case 'queued':
      return 'bg-amber-500'
    case 'completed':
      return 'bg-emerald-500'
    case 'failed':
      return 'bg-red-500'
    case 'cancelled':
      return 'bg-gray-500'
    default:
      return 'bg-gray-400'
  }
}

const statusBadgeClass = (status: string): string => {
  switch (status) {
    case 'running':
    case 'completed':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    case 'queued':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    case 'failed':
      return 'bg-red-500/10 text-red-600 dark:text-red-400'
    default:
      return 'bg-gray-500/10 text-gray-600 dark:text-gray-400'
  }
}

const severityBannerClasses = (severity: EmbeddingSeverity): string => {
  switch (severity) {
    case 'critical':
      return 'border-red-500/40 bg-red-500/10'
    case 'warning':
      return 'border-amber-500/40 bg-amber-500/10'
    default:
      return 'border-emerald-500/40 bg-emerald-500/10'
  }
}

const progressBarColor = (severity: EmbeddingSeverity): string => {
  switch (severity) {
    case 'critical':
      return 'bg-red-500'
    case 'warning':
      return 'bg-amber-500'
    default:
      return 'bg-emerald-500'
  }
}

const loadRuns = async () => {
  loading.value = true
  try {
    const response = await adminEmbeddingApi.runs()
    runs.value = response.runs
  } catch (err) {
    console.error('Failed to load embedding runs:', err)
  } finally {
    loading.value = false
  }
}

// Poll every 5s while a run is active so the live-progress card stays
// fresh. Stops polling when no active run remains; the table is then
// only refreshed on explicit Refresh-button clicks or page mounts.
const startPolling = () => {
  if (pollHandle.value !== null) return
  pollHandle.value = window.setInterval(async () => {
    if (activeRun.value === null) {
      stopPolling()
      return
    }
    await loadRuns()
  }, 5000)
}

const stopPolling = () => {
  if (pollHandle.value !== null) {
    clearInterval(pollHandle.value)
    pollHandle.value = null
  }
}

onMounted(async () => {
  await loadRuns()
  if (activeRun.value !== null) startPolling()
})

onBeforeUnmount(stopPolling)

defineExpose({ refresh: loadRuns })
</script>

<style scoped>
.animate-pulse-soft {
  animation: pulse-soft 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
@keyframes pulse-soft {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.85;
  }
}
</style>
