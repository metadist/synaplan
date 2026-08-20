<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-model-status"
    >
      <div class="max-w-6xl mx-auto space-y-6">
        <div class="surface-card p-6" data-testid="section-header">
          <div class="flex items-center gap-3 mb-2">
            <Icon icon="mdi:heart-pulse" class="w-8 h-8 text-[var(--brand)]" />
            <h1 class="text-3xl font-bold txt-primary">{{ $t('adminModelStatus.title') }}</h1>
          </div>
          <p class="txt-secondary">{{ $t('adminModelStatus.subtitle') }}</p>
        </div>

        <div v-if="isLoading" class="surface-card p-8 text-center" data-testid="state-loading">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto mb-4 txt-secondary" />
          <div class="txt-secondary">{{ $t('adminModelStatus.loading') }}</div>
        </div>

        <div
          v-else-if="!snapshot"
          class="surface-card p-8 text-center"
          data-testid="state-load-error"
        >
          <div class="txt-secondary mb-4">{{ $t('adminModelStatus.loadFailed') }}</div>
          <button class="btn-primary px-6 py-2.5 rounded-lg" data-testid="btn-retry" @click="load">
            {{ $t('common.retry') }}
          </button>
        </div>

        <template v-else>
          <div
            class="surface-card p-6 border-l-4"
            :class="
              snapshot.summary.needsAttention > 0
                ? 'border-[var(--status-error)]'
                : 'border-[var(--status-success)]'
            "
            data-testid="section-summary"
          >
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="text-xl font-bold txt-primary mb-1">
                  {{
                    snapshot.summary.needsAttention > 0
                      ? $t('adminModelStatus.summary.problems', {
                          count: snapshot.summary.needsAttention,
                        })
                      : $t('adminModelStatus.summary.allGood')
                  }}
                </h2>
                <p class="txt-secondary text-sm">
                  {{ $t('adminModelStatus.summary.lastCheck') }}:
                  {{ formatTime(snapshot.summary.lastCheck) }}
                </p>
                <p v-if="!snapshot.summary.monitoringEnabled" class="txt-secondary text-sm mt-1">
                  {{ $t('adminModelStatus.summary.monitoringOff') }}
                </p>
              </div>

              <button
                class="btn-primary px-5 py-2.5 rounded-lg flex items-center gap-2 disabled:opacity-60"
                :disabled="isRefreshing"
                data-testid="btn-refresh"
                @click="refresh()"
              >
                <Icon
                  :icon="isRefreshing ? 'mdi:loading' : 'mdi:refresh'"
                  class="w-5 h-5"
                  :class="{ 'animate-spin': isRefreshing }"
                />
                {{ $t('adminModelStatus.actions.refresh') }}
              </button>
            </div>

            <p class="txt-secondary text-xs mt-3">{{ $t('adminModelStatus.freeHint') }}</p>

            <div class="flex flex-wrap gap-2 mt-4" data-testid="summary-counts">
              <span
                v-for="tile in summaryTiles"
                :key="tile.state"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold"
                :class="badgeClass(tile.state)"
              >
                {{ tile.count }} {{ $t(`adminModelStatus.states.${tile.state}`) }}
              </span>
            </div>
          </div>

          <div
            class="surface-card p-4 flex flex-wrap items-center gap-4"
            data-testid="section-filters"
          >
            <label class="flex items-center gap-2 text-sm txt-primary cursor-pointer">
              <input v-model="onlyProblems" type="checkbox" data-testid="filter-only-problems" />
              {{ $t('adminModelStatus.filters.onlyProblems') }}
            </label>

            <label class="flex items-center gap-2 text-sm txt-primary">
              <span>{{ $t('adminModelStatus.filters.capability') }}</span>
              <select
                v-model="capabilityFilter"
                class="px-3 py-1.5 rounded-lg surface-chip txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="filter-capability"
              >
                <option value="">{{ $t('adminModelStatus.filters.allCapabilities') }}</option>
                <option v-for="cap in capabilities" :key="cap" :value="cap">
                  {{ capabilityLabel(cap) }}
                </option>
              </select>
            </label>
          </div>

          <div
            v-if="visibleProviders.length === 0"
            class="surface-card p-8 text-center txt-secondary"
            data-testid="state-empty"
          >
            {{ $t('adminModelStatus.noMatches') }}
          </div>

          <div
            v-for="provider in visibleProviders"
            :key="provider.name"
            class="space-y-3"
            data-testid="section-provider"
          >
            <div class="flex items-center gap-3 px-2">
              <h2 class="text-xl font-semibold txt-primary">{{ provider.displayName }}</h2>
              <span
                v-if="provider.needsAttention > 0"
                class="px-2.5 py-1 rounded-md text-xs font-semibold"
                :class="badgeClass('offline')"
              >
                {{ $t('adminModelStatus.provider.affected', { count: provider.needsAttention }) }}
              </span>
              <div class="h-px flex-1 bg-[var(--divider)]"></div>
              <button
                class="btn-secondary px-3 py-1.5 rounded-lg text-xs disabled:opacity-60"
                :disabled="isRefreshing"
                :aria-label="
                  $t('adminModelStatus.actions.refreshProviderAria', {
                    provider: provider.displayName,
                  })
                "
                data-testid="btn-refresh-provider"
                @click="refresh(provider.name)"
              >
                {{ $t('adminModelStatus.actions.refreshProvider') }}
              </button>
            </div>

            <div
              v-for="model in provider.models"
              :key="model.id"
              class="surface-card p-5"
              data-testid="item-model"
            >
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h3 class="text-base font-semibold txt-primary">{{ model.name }}</h3>
                    <span class="pill text-xs">{{ capabilityLabel(model.capability) }}</span>
                    <span v-if="model.autoDisabled" class="pill text-xs">
                      {{ $t('adminModelStatus.model.autoDisabled') }}
                    </span>
                    <span v-else-if="!model.active" class="pill text-xs">
                      {{ $t('adminModelStatus.model.switchedOff') }}
                    </span>
                    <span v-if="model.exemptUntil > 0" class="pill text-xs">
                      {{ $t('adminModelStatus.model.exempt') }}
                    </span>
                  </div>

                  <code class="text-xs txt-secondary font-mono opacity-70">{{
                    model.providerId
                  }}</code>

                  <p v-if="model.reason" class="txt-secondary text-sm mt-2">{{ model.reason }}</p>

                  <div class="flex flex-wrap gap-x-5 gap-y-1 mt-2 text-xs txt-secondary">
                    <span>
                      {{ $t('adminModelStatus.model.lastSuccess') }}:
                      {{ formatTime(model.lastSuccess) }}
                    </span>
                    <span v-if="model.failures > 0">
                      {{
                        $t('adminModelStatus.model.errorRate', {
                          percent: model.errorRatePercent,
                          total: model.successes + model.failures,
                        })
                      }}
                    </span>
                    <span>
                      {{ $t('adminModelStatus.model.source') }}:
                      {{ $t(`adminModelStatus.sources.${model.source}`) }}
                    </span>
                  </div>
                </div>

                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                  <span
                    class="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap"
                    :class="badgeClass(model.state)"
                    data-testid="badge-model-state"
                  >
                    {{ $t(`adminModelStatus.states.${model.state}`) }}
                  </span>

                  <div class="flex gap-2">
                    <button
                      class="btn-secondary px-3 py-1.5 rounded-lg text-xs"
                      data-testid="btn-reset-counters"
                      :disabled="busyModelId === model.id"
                      @click="resetCounters(model)"
                    >
                      {{ $t('adminModelStatus.actions.resetCounters') }}
                    </button>
                    <button
                      class="btn-secondary px-3 py-1.5 rounded-lg text-xs"
                      data-testid="btn-toggle-exempt"
                      :disabled="busyModelId === model.id"
                      @click="toggleExempt(model)"
                    >
                      {{
                        model.exemptUntil > 0
                          ? $t('adminModelStatus.actions.unexempt')
                          : $t('adminModelStatus.actions.exempt')
                      }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import {
  modelStatusApi,
  type ModelStatusEntry,
  type ModelStatusSnapshot,
  type ModelStatusState,
} from '@/services/api/adminModelStatusApi'

const { t, locale } = useI18n()
const { success, error } = useNotification()
const { confirm } = useDialog()

const snapshot = ref<ModelStatusSnapshot | null>(null)
const isLoading = ref(false)
const isRefreshing = ref(false)
const onlyProblems = ref(false)
const capabilityFilter = ref('')
const busyModelId = ref<number | null>(null)

const ORDERED_STATES: ModelStatusState[] = [
  'offline',
  'degraded',
  'online',
  'unconfigured',
  'unknown',
]

const summaryTiles = computed(() => {
  const summary = snapshot.value?.summary
  if (!summary) return []

  return ORDERED_STATES.map((state) => ({ state, count: summary[state] })).filter(
    (tile) => tile.count > 0
  )
})

const capabilities = computed(() => {
  const seen = new Set<string>()
  for (const provider of snapshot.value?.providers ?? []) {
    for (const model of provider.models) {
      seen.add(model.capability)
    }
  }
  return [...seen].sort()
})

const visibleProviders = computed(() => {
  return (snapshot.value?.providers ?? [])
    .map((provider) => ({
      ...provider,
      models: provider.models.filter((model) => {
        if (capabilityFilter.value && model.capability !== capabilityFilter.value) return false
        if (onlyProblems.value && model.state !== 'offline' && model.state !== 'degraded')
          return false
        return true
      }),
    }))
    .filter((provider) => provider.models.length > 0)
})

/**
 * Muted background plus the matching ink token, never white on a filled
 * status colour. The filled variants are tuned for light backgrounds: white
 * text on `--status-warning` measures 2.9:1 in light theme and 1.5:1 in dark,
 * both far below the 4.5:1 these badges need. The muted pairs stay between
 * 6.4:1 and 8.9:1 in both themes and are defined for both, so they also
 * survive the V2 glass surfaces.
 */
const badgeClass = (state: ModelStatusState): string => {
  switch (state) {
    case 'online':
      return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
    case 'degraded':
      return 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
    case 'offline':
      return 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    default:
      return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
  }
}

const formatTime = (unix: number): string => {
  if (!unix) return t('adminModelStatus.never')
  return new Date(unix * 1000).toLocaleString(locale.value)
}

const capabilityLabel = (tag: string): string => {
  const key = `config.aiModels.capabilities.${tag}`
  const label = t(key)
  return label === key ? tag : label
}

const load = async () => {
  isLoading.value = true
  try {
    snapshot.value = await modelStatusApi.getStatus()
  } catch {
    snapshot.value = null
  } finally {
    isLoading.value = false
  }
}

const refresh = async (provider?: string) => {
  isRefreshing.value = true
  try {
    const result = await modelStatusApi.refresh(provider)
    snapshot.value = await modelStatusApi.getStatus()
    success(t('adminModelStatus.messages.refreshed', { count: result.checked }))
  } catch {
    error(t('adminModelStatus.messages.refreshFailed'))
  } finally {
    isRefreshing.value = false
  }
}

const toggleExempt = async (model: ModelStatusEntry) => {
  const exempt = model.exemptUntil === 0
  busyModelId.value = model.id
  try {
    await modelStatusApi.setExempt(model.id, exempt)
    snapshot.value = await modelStatusApi.getStatus()
    success(
      exempt ? t('adminModelStatus.messages.exempted') : t('adminModelStatus.messages.unexempted')
    )
  } catch {
    error(t('adminModelStatus.messages.exemptFailed'))
  } finally {
    busyModelId.value = null
  }
}

const resetCounters = async (model: ModelStatusEntry) => {
  const confirmed = await confirm({
    title: t('adminModelStatus.confirmResetTitle'),
    message: t('adminModelStatus.confirmResetMessage', { name: model.name }),
    confirmText: t('adminModelStatus.actions.resetCounters'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  busyModelId.value = model.id
  try {
    await modelStatusApi.resetCounters(model.id)
    snapshot.value = await modelStatusApi.getStatus()
    success(t('adminModelStatus.messages.countersReset'))
  } catch {
    error(t('adminModelStatus.messages.countersResetFailed'))
  } finally {
    busyModelId.value = null
  }
}

onMounted(load)
</script>
