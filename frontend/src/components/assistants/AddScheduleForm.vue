<template>
  <div class="space-y-4" data-testid="form-add-schedule">
    <div class="grid gap-3 sm:grid-cols-3">
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.triggers.scheduleRun') }}</span>
        <select
          v-model="unit"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-schedule-unit"
        >
          <option value="hour">{{ $t('assistants.triggers.everyHour') }}</option>
          <option value="day">{{ $t('assistants.triggers.everyDay') }}</option>
          <option value="weekday">{{ $t('assistants.triggers.everyWeekday') }}</option>
          <option value="week">{{ $t('assistants.triggers.everyWeek') }}</option>
          <option value="month">{{ $t('assistants.triggers.everyMonth') }}</option>
        </select>
      </label>
      <label v-if="unit === 'week'" class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.triggers.onDay') }}</span>
        <select
          v-model="on"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-schedule-on"
        >
          <option v-for="day in weekdays" :key="day.value" :value="day.value">
            {{ day.label }}
          </option>
        </select>
      </label>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.triggers.atTime') }}</span>
        <input
          v-model="at"
          type="time"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="input-schedule-at"
        />
      </label>
    </div>
    <p class="txt-secondary text-sm" data-testid="schedule-timezone">{{ timezoneMessage }}</p>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.triggers.askItTo') }}</span>
      <textarea
        v-model="instruction"
        rows="3"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-schedule-instruction"
      />
    </label>
    <p class="txt-secondary text-sm">{{ $t('assistants.triggers.scheduleHint') }}</p>
    <details class="txt-secondary text-sm">
      <summary class="cursor-pointer">{{ $t('assistants.triggers.advancedCron') }}</summary>
      <input
        v-model="cron"
        class="mt-2 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-schedule-cron"
      />
    </details>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="btn-cancel-schedule"
        @click="emit('cancel')"
      >
        {{ $t('common.cancel') }}
      </button>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="!canSave"
        data-testid="btn-save-schedule"
        @click="onSave"
      >
        {{ $t('assistants.triggers.addSchedule') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ensureAccountTimezone,
  type EnsuredAccountTimezone,
} from '@/composables/useAccountTimezone'

const emit = defineEmits<{
  cancel: []
  save: [payload: Record<string, unknown>]
}>()

const { t } = useI18n()
const unit = ref<'hour' | 'day' | 'weekday' | 'week' | 'month'>('week')
const on = ref('monday')
const at = ref('08:00')
const instruction = ref('')
const cron = ref('')
const resolving = ref(true)
const ensured = ref<EnsuredAccountTimezone | null>(null)

const zone = computed(() => ensured.value?.tz ?? '')
const canSave = computed(() => instruction.value.trim() !== '' && zone.value !== '')
const timezoneMessage = computed(() => {
  if (resolving.value) return t('assistants.triggers.timezoneLoading')
  if (!ensured.value) return t('assistants.triggers.timezoneLoadFailed')
  if (ensured.value.source === 'device-unsaved') {
    return t('assistants.triggers.timezoneDeviceUnsaved', { tz: ensured.value.tz })
  }
  return t('assistants.triggers.timezoneHint', { tz: ensured.value.tz })
})

onMounted(() => {
  void ensureAccountTimezone().then((result) => {
    ensured.value = result
    resolving.value = false
  })
})

const weekdays = computed(() => [
  { value: 'monday', label: t('assistants.triggers.monday') },
  { value: 'tuesday', label: t('assistants.triggers.tuesday') },
  { value: 'wednesday', label: t('assistants.triggers.wednesday') },
  { value: 'thursday', label: t('assistants.triggers.thursday') },
  { value: 'friday', label: t('assistants.triggers.friday') },
  { value: 'saturday', label: t('assistants.triggers.saturday') },
  { value: 'sunday', label: t('assistants.triggers.sunday') },
])

function onSave(): void {
  if (!canSave.value || !zone.value) {
    return
  }
  const payload: Record<string, unknown> = {
    id: `sch-${Math.random().toString(36).slice(2, 8)}`,
    name: instruction.value.trim().slice(0, 80),
    tz: zone.value,
    instruction: instruction.value.trim(),
    allowUnattended: false,
    enabled: true,
  }
  if (cron.value.trim()) {
    payload.cron = cron.value.trim()
  } else {
    payload.every = { unit: unit.value, on: on.value, at: at.value }
  }
  emit('save', payload)
}
</script>
