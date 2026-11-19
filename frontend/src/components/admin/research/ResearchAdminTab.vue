<template>
  <div data-testid="research-admin-tab">
    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ t('adminSetup.research.loadFailed') }}</p>
      <button type="button" class="btn-primary px-4 py-2.5 text-sm font-medium mt-4" @click="load">
        {{ t('common.retry') }}
      </button>
    </div>

    <template v-else-if="form">
      <section class="surface-card rounded-lg p-4 mb-6" data-testid="research-admin-status">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h2 class="text-lg font-semibold txt-primary">
              {{
                form.enabled ? t('adminSetup.research.onTitle') : t('adminSetup.research.offTitle')
              }}
            </h2>
            <p class="text-sm txt-secondary mt-1">
              {{ form.enabled ? t('adminSetup.research.onBody') : t('adminSetup.research.offBody') }}
            </p>
          </div>
          <GatewaySettingToggle
            :model-value="form.enabled"
            :label="
              form.enabled ? t('adminSetup.research.onTitle') : t('adminSetup.research.offTitle')
            "
            :disabled="switching || form.envLocked"
            testid="research-admin-enabled"
            @update:model-value="setEnabled"
          />
        </div>
        <p v-if="form.envLocked" class="text-sm txt-secondary mt-3">
          {{ t('adminSetup.research.envLocked') }}
        </p>
        <p v-else-if="form.enabled" class="text-sm txt-secondary mt-3">
          {{ t('adminSetup.research.turnOffHint') }}
        </p>
        <p
          v-if="errorPlace === 'switch'"
          class="text-sm text-red-600 dark:text-red-400 mt-3"
          role="alert"
        >
          {{ errorText }}
        </p>
      </section>

      <ResearchAutoSwitch
        :research-on="form.enabled"
        :auto-enabled="form.autoEnabled"
        :web-search-available="form.webSearchAvailable"
        @saved="adoptSwitch"
      />

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">{{ t('adminSetup.research.teamTitle') }}</h2>
        <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.planningNote') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label v-for="slot in modelSlots" :key="slot.field" class="block text-sm">
            <span class="font-medium txt-primary">{{ t(slot.label) }}</span>
            <select
              v-model="models[slot.field]"
              :class="FIELD_CLASS"
              :data-testid="slot.testid"
              @change="commitModel(slot.field)"
            >
              <option value="">{{ t('adminSetup.research.recommended') }}</option>
              <option
                v-for="model in form.models"
                :key="model.id"
                :value="String(model.id)"
                :disabled="!model.available && String(model.id) !== models[slot.field]"
              >
                {{ modelLabel(model) }}
              </option>
            </select>
          </label>
        </div>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">
          {{ t('adminSetup.research.budgetsTitle') }}
        </h2>
        <div v-for="depth in DEPTHS" :key="depth" class="mt-4">
          <h3 class="text-sm font-semibold txt-primary">
            {{ t(`adminSetup.research.depth.${depth}`) }}
          </h3>
          <div class="mt-2 grid gap-3 grid-cols-2 sm:grid-cols-4">
            <label
              v-for="field in BUDGET_FIELDS"
              :key="field.key"
              class="block text-sm txt-primary"
            >
              {{ t(field.label) }}
              <input
                v-model.number="form.budgets[depth][field.key]"
                :class="FIELD_CLASS"
                type="number"
                :min="field.min"
                :max="field.max"
                :step="field.step"
                :data-testid="`research-admin-${depth}-${field.key}`"
                @change="commitBudget(depth, field.key)"
                @blur="commitBudget(depth, field.key)"
                @keydown.enter="leaveField"
              />
            </label>
          </div>
        </div>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">
          {{ t('adminSetup.research.limitsTitle') }}
        </h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label v-for="field in LIMIT_FIELDS" :key="field.key" class="block text-sm txt-primary">
            {{ t(field.label) }}
            <input
              v-model.number="form.limits[field.key]"
              :class="FIELD_CLASS"
              type="number"
              :min="field.min"
              :max="field.max"
              step="1"
              :data-testid="`research-admin-${field.key}`"
              @change="commitLimit(field.key)"
              @blur="commitLimit(field.key)"
              @keydown.enter="leaveField"
            />
          </label>
        </div>
      </section>

      <p
        v-if="saveStatus"
        class="text-sm mb-6"
        :class="justSaved ? 'font-medium txt-primary' : 'txt-secondary'"
        role="status"
        aria-live="polite"
        data-testid="research-admin-save-status"
      >
        {{ saveStatus }}
      </p>
      <p
        v-if="errorPlace === 'save'"
        class="text-sm text-red-600 dark:text-red-400 mb-6"
        role="alert"
        data-testid="research-admin-save-error"
      >
        {{ errorText }}
      </p>

      <ResearchSampleCard
        :enabled="form.enabled"
        :dirty="dirty"
        :quick-cost-cap="stored?.budgets.quick.costCap ?? 0"
        :sample="form.sample"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import GatewaySettingToggle from '@/components/config/messagesGateway/GatewaySettingToggle.vue'
import ResearchSampleCard from './ResearchSampleCard.vue'
import ResearchAutoSwitch from './ResearchAutoSwitch.vue'
import { useResearchAdminForm } from './useResearchAdminForm'

const { t } = useI18n()
const {
  FIELD_CLASS,
  DEPTHS,
  BUDGET_FIELDS,
  LIMIT_FIELDS,
  modelSlots,
  loading,
  loadFailed,
  load,
  form,
  stored,
  switching,
  setEnabled,
  models,
  commitModel,
  modelLabel,
  commitBudget,
  commitLimit,
  leaveField,
  errorPlace,
  errorText,
  saveStatus,
  justSaved,
  dirty,
  adoptSwitch,
} = useResearchAdminForm()
</script>
