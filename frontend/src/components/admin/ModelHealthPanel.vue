<template>
  <div class="space-y-6" data-testid="panel-model-health">
    <p class="text-sm txt-secondary">{{ $t('adminModelStatus.subtitle') }}</p>

    <div v-if="isLoading" class="surface-card p-8 text-center" data-testid="state-loading">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto mb-4 txt-secondary" />
      <div class="txt-secondary">{{ $t('adminModelStatus.loading') }}</div>
    </div>

    <div v-else-if="!snapshot" class="surface-card p-8 text-center" data-testid="state-load-error">
      <div class="txt-secondary mb-4">{{ $t('adminModelStatus.loadFailed') }}</div>
      <button
        type="button"
        class="btn-primary px-6 py-2.5 rounded-xl"
        data-testid="btn-retry"
        @click="load"
      >
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
            type="button"
            class="btn-primary px-5 py-2.5 rounded-xl flex items-center gap-2 disabled:opacity-60"
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
            :key="tile.key"
            class="px-3 py-1.5 rounded-lg text-xs font-semibold"
            :class="tile.badgeClass"
          >
            {{ tile.count }} {{ tile.label }}
          </span>
        </div>
      </div>

      <div class="surface-card p-4 flex flex-wrap items-center gap-4" data-testid="section-filters">
        <label class="flex items-center gap-2 text-sm txt-primary cursor-pointer">
          <input v-model="onlyProblems" type="checkbox" data-testid="filter-only-problems" />
          {{ $t('adminModelStatus.filters.onlyProblems') }}
        </label>

        <label class="flex items-center gap-2 text-sm txt-primary">
          <span>{{ $t('adminModelStatus.filters.capability') }}</span>
          <select
            v-model="capabilityFilter"
            class="px-3 py-1.5 rounded-xl surface-chip txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="filter-capability"
          >
            <option value="">{{ $t('adminModelStatus.filters.allCapabilities') }}</option>
            <option v-for="cap in capabilities" :key="cap" :value="cap">
              {{ capabilityLabel(cap) }}
            </option>
          </select>
        </label>

        <div v-if="visibleProviders.length > 1" class="ml-auto">
          <button
            type="button"
            class="btn-secondary px-4 py-2 rounded-xl text-sm font-medium"
            data-testid="btn-model-status-toggle-all"
            @click="allProvidersOpen ? collapseAllProviders() : expandAllProviders()"
          >
            {{
              allProvidersOpen
                ? $t('admin.config.accordion.collapseAll')
                : $t('admin.config.accordion.expandAll')
            }}
          </button>
        </div>
      </div>

      <div
        v-if="visibleProviders.length === 0 && visibleRetired.length === 0"
        class="surface-card p-8 text-center txt-secondary"
        data-testid="state-empty"
      >
        {{ $t('adminModelStatus.noMatches') }}
      </div>

      <AccordionStack v-if="visibleProviders.length > 0" testid="model-status-accordion">
        <AccordionSection
          v-for="provider in visibleProviders"
          :key="provider.name"
          :panel-id="providerPanelId(provider.name)"
          :title="provider.displayName"
          :open="isProviderOpen(provider.name)"
          testid="section-provider"
          :header-testid="`btn-provider-${provider.name}`"
          @toggle="toggleProvider(provider.name)"
        >
          <template v-if="provider.needsAttention > 0" #badge>
            <span
              class="px-2.5 py-1 rounded-md text-xs font-semibold"
              :class="badgeClass('offline')"
            >
              {{ $t('adminModelStatus.provider.affected', { count: provider.needsAttention }) }}
            </span>
          </template>
          <template #actions>
            <button
              type="button"
              class="btn-secondary px-3 py-1.5 rounded-xl text-xs disabled:opacity-60"
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
          </template>

          <AccordionStack :testid="`provider-models-${provider.name}`">
            <AccordionSection
              v-for="model in provider.models"
              :key="model.id"
              :panel-id="`model-status-model-${model.id}`"
              :title="model.name"
              :open="isModelOpen(String(model.id))"
              testid="item-model"
              :header-testid="`btn-model-${model.id}`"
              @toggle="toggleModel(String(model.id))"
            >
              <template #badge>
                <span class="pill text-xs">{{ capabilityLabel(model.capability) }}</span>
                <span
                  class="px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wide whitespace-nowrap"
                  :class="badgeClass(model.state)"
                  data-testid="badge-model-state"
                >
                  {{ $t(`adminModelStatus.states.${model.state}`) }}
                </span>
              </template>

              <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap mb-1">
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

                  <p v-if="model.reason" class="txt-secondary text-sm mt-2">
                    {{ model.reason }}
                  </p>

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

                <div class="flex gap-2 flex-shrink-0">
                  <button
                    type="button"
                    class="btn-secondary px-3 py-1.5 rounded-xl text-xs"
                    data-testid="btn-reset-counters"
                    :disabled="busyModelId === model.id"
                    @click="resetCounters(model)"
                  >
                    {{ $t('adminModelStatus.actions.resetCounters') }}
                  </button>
                  <button
                    type="button"
                    class="btn-secondary px-3 py-1.5 rounded-xl text-xs"
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

              <p
                v-if="(model.unavailableCount ?? 0) > 0"
                class="mt-3 text-sm txt-secondary"
                data-testid="text-unavailable-count"
              >
                {{
                  $t('adminModelStatus.alternatives.unavailable', {
                    count: model.unavailableCount,
                  })
                }}
              </p>
              <div
                v-if="alternativesFor(model).length > 0"
                class="mt-3 space-y-2"
                data-testid="model-alternatives"
              >
                <p class="text-sm txt-primary">{{ $t('adminModelStatus.alternatives.hint') }}</p>
                <div v-if="model.defaultCapability" class="flex flex-wrap items-center gap-2">
                  <select
                    :value="selectedAlternative(model)"
                    class="min-w-0 flex-1 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    :aria-label="$t('adminModelStatus.alternatives.hint')"
                    data-testid="select-model-alternative"
                    @change="onPickAlternative(model, $event)"
                  >
                    <option
                      v-for="alternative in alternativesFor(model)"
                      :key="alternative.id"
                      :value="alternative.id"
                    >
                      {{ alternative.name }}
                    </option>
                  </select>
                  <button
                    type="button"
                    class="btn-secondary px-3 py-1.5 rounded-xl text-xs"
                    :disabled="busyModelId === model.id"
                    data-testid="btn-use-alternative"
                    @click="useAlternative(model)"
                  >
                    {{ $t('adminModelStatus.alternatives.use') }}
                  </button>
                </div>
                <ul v-else class="text-sm txt-secondary">
                  <li v-for="alternative in alternativesFor(model)" :key="alternative.id">
                    {{ alternative.name }}
                  </li>
                </ul>
              </div>
            </AccordionSection>
          </AccordionStack>
        </AccordionSection>
      </AccordionStack>

      <ModelHealthRetiredList v-if="visibleRetired.length > 0" :models="visibleRetired" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import ModelHealthRetiredList from '@/components/admin/ModelHealthRetiredList.vue'
import { useAccordion } from '@/composables/useAccordion'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { setModelsNeedingAttention } from '@/composables/useNavItems'
import {
  modelStatusApi,
  type ModelStatusEntry,
  type ModelStatusSnapshot,
  type ModelStatusState,
} from '@/services/api/adminModelStatusApi'
import { saveDefaultModels } from '@/services/api/configApi'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { Capability } from '@/types/ai-models'
import { interpretDefaultSave } from '@/utils/defaultModelOfferResult'

const { t, locale } = useI18n()
const { success, error } = useNotification()
const { confirm } = useDialog()

const snapshot = ref<ModelStatusSnapshot | null>(null)
const isLoading = ref(false)
const isRefreshing = ref(false)
const onlyProblems = ref(false)
const capabilityFilter = ref('')
const busyModelId = ref<number | null>(null)
const pickedAlternative = ref<Record<number, number>>({})

/** Keeps the Operate badge in step with what this panel shows. */
async function fetchSnapshot(): Promise<void> {
  snapshot.value = await modelStatusApi.getStatus()
  setModelsNeedingAttention(snapshot.value.summary.needsAttention)
}

const ORDERED_STATES: ModelStatusState[] = [
  'offline',
  'degraded',
  'online',
  'unconfigured',
  'unknown',
]

const NEUTRAL_BADGE_CLASS = 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'

const summaryTiles = computed(() => {
  const summary = snapshot.value?.summary
  if (!summary) return []

  const tiles = ORDERED_STATES.map((state) => ({
    key: state as string,
    count: summary[state],
    label: t(`adminModelStatus.states.${state}`),
    badgeClass: badgeClass(state),
  }))
  tiles.push({
    key: 'switchedOff',
    count: summary.switchedOff,
    label: t('adminModelStatus.model.switchedOff'),
    badgeClass: NEUTRAL_BADGE_CLASS,
  })
  tiles.push({
    key: 'retired',
    count: summary.retired,
    label: t('adminModelStatus.retired.label'),
    badgeClass: NEUTRAL_BADGE_CLASS,
  })

  return tiles.filter((tile) => tile.count > 0)
})

const capabilities = computed(() => {
  const seen = new Set<string>()
  for (const provider of snapshot.value?.providers ?? []) {
    for (const model of provider.models) {
      seen.add(model.capability)
    }
  }
  for (const model of snapshot.value?.retired ?? []) {
    seen.add(model.capability)
  }
  return [...seen].sort()
})

/** Retired models are never a problem, so the problem filter hides them. */
const visibleRetired = computed(() => {
  if (onlyProblems.value) return []
  return (snapshot.value?.retired ?? []).filter(
    (model) => !capabilityFilter.value || model.capability === capabilityFilter.value
  )
})

const visibleProviders = computed(() => {
  return (snapshot.value?.providers ?? [])
    .map((provider) => ({
      ...provider,
      models: provider.models.filter((model) => {
        if (capabilityFilter.value && model.capability !== capabilityFilter.value) return false
        if (onlyProblems.value && !model.needsAttention) return false
        return true
      }),
    }))
    .filter((provider) => provider.models.length > 0)
})

const providerIds = computed(() => visibleProviders.value.map((provider) => provider.name))
const modelIds = computed(() =>
  visibleProviders.value.flatMap((provider) => provider.models.map((model) => String(model.id)))
)
const {
  isOpen: isProviderOpen,
  toggle: toggleProvider,
  expandAll: expandAllProviders,
  collapseAll: collapseAllProviders,
  allOpen: allProvidersOpen,
} = useAccordion(providerIds)
const { isOpen: isModelOpen, toggle: toggleModel } = useAccordion(modelIds)

function providerPanelId(name: string): string {
  return `model-status-provider-${name.replace(/[^a-zA-Z0-9_-]+/g, '-')}`
}

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
      return NEUTRAL_BADGE_CLASS
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
    await fetchSnapshot()
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
    await fetchSnapshot()
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
    await fetchSnapshot()
    success(
      exempt ? t('adminModelStatus.messages.exempted') : t('adminModelStatus.messages.unexempted')
    )
  } catch {
    error(t('adminModelStatus.messages.exemptFailed'))
  } finally {
    busyModelId.value = null
  }
}

function alternativesFor(model: ModelStatusEntry) {
  return model.alternatives ?? []
}

function selectedAlternative(model: ModelStatusEntry): number | null {
  const alternatives = alternativesFor(model)
  const picked = pickedAlternative.value[model.id]
  if (picked !== undefined && alternatives.some((alternative) => alternative.id === picked)) {
    return picked
  }
  return alternatives[0]?.id ?? null
}

function onPickAlternative(model: ModelStatusEntry, event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value)
  if (!Number.isFinite(value)) return
  pickedAlternative.value = { ...pickedAlternative.value, [model.id]: value }
}

const useAlternative = async (model: ModelStatusEntry) => {
  const capability = model.defaultCapability
  const alternativeId = selectedAlternative(model)
  const alternative = alternativesFor(model).find((item) => item.id === alternativeId)
  if (!capability || !alternative) return

  const purpose = capabilityLabel(capability.toLowerCase())
  const confirmed = await confirm({
    title: t('adminModelStatus.alternatives.confirmTitle'),
    message: t('adminModelStatus.alternatives.confirmMessage', {
      name: alternative.name,
      purpose,
    }),
    confirmText: t('adminModelStatus.alternatives.use'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  busyModelId.value = model.id
  try {
    const response = await saveDefaultModels({
      defaults: { [capability]: alternative.id } as Partial<Record<Capability, number>>,
    })
    const outcome = interpretDefaultSave(response, capability, alternative.id)
    if (outcome.kind === 'skipped') {
      error(t('adminModelStatus.alternatives.saveFailed', { name: model.name }))
      return
    }
    const store = useAiConfigStore()
    store.defaults[capability as Capability] = outcome.modelId
    if (outcome.kind === 'unusable') {
      error(t('adminModelStatus.alternatives.savedUnusable', { name: alternative.name }))
      return
    }
    const appliedName =
      outcome.kind === 'replaced'
        ? (store.models[capability as Capability]?.find((item) => item.id === outcome.modelId)
            ?.name ?? t('adminModelStatus.alternatives.otherModel'))
        : alternative.name
    success(
      t(
        outcome.kind === 'replaced'
          ? 'adminModelStatus.alternatives.savedOther'
          : 'adminModelStatus.alternatives.saved',
        { picked: alternative.name, name: appliedName, purpose }
      )
    )
  } catch {
    error(t('adminModelStatus.alternatives.saveFailed', { name: model.name }))
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
    await fetchSnapshot()
    success(t('adminModelStatus.messages.countersReset'))
  } catch {
    error(t('adminModelStatus.messages.countersResetFailed'))
  } finally {
    busyModelId.value = null
  }
}

onMounted(load)
</script>
