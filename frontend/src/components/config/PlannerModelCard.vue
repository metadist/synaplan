<template>
  <div class="surface-card overflow-hidden" data-testid="section-routing-planner-model">
    <button
      type="button"
      class="w-full p-6 flex items-start justify-between gap-3 text-left hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors"
      :aria-expanded="expanded"
      data-testid="btn-toggle-agent-models"
      @click="expanded = !expanded"
    >
      <div class="flex items-start gap-3 min-w-0">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10 flex-shrink-0">
          <CpuChipIcon class="w-5 h-5 text-[var(--brand)]" aria-hidden="true" />
        </div>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-lg font-semibold txt-primary">
              {{
                researchEnabled
                  ? $t('config.routing.agentTuningTitle')
                  : $t('config.routing.plannerModelTitle')
              }}
            </h3>
            <span
              class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-black/5 dark:bg-white/10 txt-secondary"
              data-testid="badge-agent-models-summary"
            >
              {{
                activeOverridesCount > 0
                  ? $t('config.routing.agentTuningCustomCount', { count: activeOverridesCount })
                  : $t('config.routing.agentTuningAllAuto')
              }}
            </span>
          </div>
          <p class="text-sm txt-secondary mt-0.5">
            {{
              researchEnabled
                ? $t('config.routing.agentTuningDesc')
                : $t('config.routing.plannerModelDesc')
            }}
          </p>
        </div>
      </div>
      <ChevronDownIcon
        class="w-5 h-5 txt-secondary flex-shrink-0 mt-1 transition-transform duration-200"
        :class="expanded && 'rotate-180'"
        aria-hidden="true"
      />
    </button>

    <div
      v-show="expanded"
      class="px-6 pb-6 pt-2 border-t border-light-border/30 dark:border-dark-border/20 space-y-4"
      data-testid="panel-agent-models"
    >
      <div class="grid grid-cols-1 gap-4" :class="researchEnabled ? 'md:grid-cols-3' : 'max-w-md'">
        <div>
          <label class="block text-sm font-medium txt-primary mb-1" for="planner-model-select">
            {{ $t('config.routing.plannerSlotLabel') }}
          </label>
          <select
            id="planner-model-select"
            :value="plannerModelId ?? ''"
            :disabled="loading || savingSlot === 'modelId'"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="select-planner-model"
            @change="onChange('modelId', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">{{ $t('config.routing.plannerModelAuto') }}</option>
            <option v-for="model in plannerModels" :key="model.id" :value="model.id">
              {{ model.name }} ({{ model.service }})
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1.5">
            {{ $t('config.routing.plannerSlotHint') }}
          </p>
        </div>

        <div v-if="researchEnabled" data-testid="slot-research-reading">
          <label
            class="block text-sm font-medium txt-primary mb-1"
            for="research-reading-model-select"
          >
            {{ $t('config.routing.readingSlotLabel') }}
          </label>
          <select
            id="research-reading-model-select"
            :value="readingModelId ?? ''"
            :disabled="loading || savingSlot === 'readingModelId'"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="select-research-reading-model"
            @change="onChange('readingModelId', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">{{ $t('config.routing.readingModelAuto') }}</option>
            <option v-for="model in plannerModels" :key="model.id" :value="model.id">
              {{ model.name }} ({{ model.service }})
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1.5">
            {{ $t('config.routing.readingSlotHint') }}
          </p>
        </div>

        <div v-if="researchEnabled" data-testid="slot-research-checking">
          <label
            class="block text-sm font-medium txt-primary mb-1"
            for="research-checking-model-select"
          >
            {{ $t('config.routing.checkingSlotLabel') }}
          </label>
          <select
            id="research-checking-model-select"
            :value="checkingModelId ?? ''"
            :disabled="loading || savingSlot === 'checkingModelId'"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="select-research-checking-model"
            @change="onChange('checkingModelId', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">{{ $t('config.routing.checkingModelAuto') }}</option>
            <option v-for="model in plannerModels" :key="model.id" :value="model.id">
              {{ model.name }} ({{ model.service }})
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1.5">
            {{ $t('config.routing.checkingSlotHint') }}
          </p>
        </div>
      </div>

      <p
        v-if="researchEnabled"
        class="text-xs txt-secondary pt-2 border-t border-light-border/20 dark:border-dark-border/15"
        data-testid="text-research-lead-note"
      >
        {{ $t('config.routing.researchLeadNote') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon, CpuChipIcon } from '@heroicons/vue/24/outline'
import {
  getModels,
  getPlannerModel,
  savePlannerModel,
  type SaveAgentModelsRequest,
} from '@/services/api/configApi'
import type { AIModel } from '@/types/ai-models'
import { useNotification } from '@/composables/useNotification'

type AgentSlotKey = keyof SaveAgentModelsRequest

const { t } = useI18n()
const { success, error } = useNotification()

const plannerModels = ref<AIModel[]>([])
const plannerModelId = ref<number | null>(null)
const readingModelId = ref<number | null>(null)
const checkingModelId = ref<number | null>(null)
const researchEnabled = ref(false)
const expanded = ref(false)
const loading = ref(false)
const savingSlot = ref<AgentSlotKey | null>(null)

const activeOverridesCount = computed(() => {
  const slots = researchEnabled.value
    ? [plannerModelId.value, readingModelId.value, checkingModelId.value]
    : [plannerModelId.value]
  return slots.filter((id) => id !== null).length
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const [modelsRes, plannerRes] = await Promise.all([getModels(), getPlannerModel()])
    plannerModels.value = modelsRes.success ? (modelsRes.models.CHAT ?? []) : []
    plannerModelId.value = plannerRes.modelId ?? null
    readingModelId.value = plannerRes.readingModelId ?? null
    checkingModelId.value = plannerRes.checkingModelId ?? null
    researchEnabled.value = plannerRes.researchEnabled === true
  } catch {
    error(t('config.routing.plannerModelLoadFailed'))
  } finally {
    loading.value = false
  }
}

async function onChange(slot: AgentSlotKey, rawValue: string): Promise<void> {
  const nextId = rawValue === '' ? null : Number(rawValue)
  const slotRef =
    slot === 'readingModelId'
      ? readingModelId
      : slot === 'checkingModelId'
        ? checkingModelId
        : plannerModelId
  const previous = slotRef.value
  slotRef.value = nextId
  savingSlot.value = slot
  try {
    await savePlannerModel({ [slot]: nextId })
    success(t('config.routing.plannerModelSaved'))
  } catch {
    slotRef.value = previous
    error(t('config.routing.plannerModelSaveFailed'))
  } finally {
    savingSlot.value = null
  }
}

onMounted(load)
</script>
