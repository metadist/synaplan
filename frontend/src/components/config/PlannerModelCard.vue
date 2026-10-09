<template>
  <div class="surface-card overflow-hidden" data-testid="section-routing-planner-model">
    <div class="p-6">
      <div class="flex items-start gap-3 mb-4">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10 flex-shrink-0">
          <Icon icon="heroicons:cpu-chip" class="w-5 h-5 text-[var(--brand)]" />
        </div>
        <div class="min-w-0">
          <h3 class="text-lg font-semibold txt-primary">
            {{ $t('config.routing.plannerModelTitle') }}
          </h3>
          <p class="text-sm txt-secondary mt-0.5">
            {{ $t('config.routing.plannerModelDesc') }}
          </p>
        </div>
      </div>

      <label class="block text-sm font-medium txt-primary mb-2" for="planner-model-select">
        {{ $t('config.routing.plannerModelLabel') }}
      </label>
      <select
        id="planner-model-select"
        :value="plannerModelId ?? ''"
        :disabled="loading || saving"
        class="w-full max-w-md px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="select-planner-model"
        @change="onChange(($event.target as HTMLSelectElement).value)"
      >
        <option value="">{{ $t('config.routing.plannerModelAuto') }}</option>
        <option v-for="model in plannerModels" :key="model.id" :value="model.id">
          {{ model.name }} ({{ model.service }})
        </option>
      </select>

      <p v-if="plannerModelId === null" class="text-xs txt-secondary mt-2">
        {{ $t('config.routing.plannerModelFallbackHint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { getModels, getPlannerModel, savePlannerModel } from '@/services/api/configApi'
import type { AIModel } from '@/types/ai-models'
import { useNotification } from '@/composables/useNotification'

// Per-user DEFAULTMODEL.PLAN. `null` means no override: the planner falls back
// to the user's sorting model.
const { t } = useI18n()
const { success, error } = useNotification()

const plannerModels = ref<AIModel[]>([])
const plannerModelId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const [modelsRes, plannerRes] = await Promise.all([getModels(), getPlannerModel()])
    plannerModels.value = modelsRes.success ? (modelsRes.models.CHAT ?? []) : []
    plannerModelId.value = plannerRes.modelId
  } catch {
    error(t('config.routing.plannerModelLoadFailed'))
  } finally {
    loading.value = false
  }
}

async function onChange(rawValue: string): Promise<void> {
  const nextId = rawValue === '' ? null : Number(rawValue)
  const previous = plannerModelId.value
  plannerModelId.value = nextId
  saving.value = true
  try {
    await savePlannerModel(nextId)
    success(t('config.routing.plannerModelSaved'))
  } catch {
    plannerModelId.value = previous
    error(t('config.routing.plannerModelSaveFailed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
