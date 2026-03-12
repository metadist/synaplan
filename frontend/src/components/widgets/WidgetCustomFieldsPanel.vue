<script setup lang="ts">
import { ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { saveCustomFieldValues } from '@/services/api/widgetSessionsApi'

interface CustomFieldDef {
  id: string
  name: string
  type: 'text' | 'boolean'
}

interface Props {
  customFields: CustomFieldDef[]
  widgetId: string
  sessionId: string
}

const props = defineProps<Props>()
const { t } = useI18n()
const { error: showError } = useNotification()

const values = ref<Record<string, string | boolean>>({})
const saving = ref(false)
const dirty = ref(false)
let saveTimer: ReturnType<typeof setTimeout> | null = null

const initValues = () => {
  const v: Record<string, string | boolean> = {}
  for (const field of props.customFields) {
    v[field.id] = values.value[field.id] ?? (field.type === 'boolean' ? false : '')
  }
  values.value = v
}

initValues()

watch(() => props.customFields, initValues, { deep: true })

const persistValues = async () => {
  if (!props.sessionId) return
  saving.value = true
  try {
    await saveCustomFieldValues(props.widgetId, props.sessionId, values.value)
    dirty.value = false
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : t('widgets.customFields.saveFailed')
    showError(message)
  } finally {
    saving.value = false
  }
}

const debouncedSave = () => {
  dirty.value = true
  if (!props.sessionId) return
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(persistValues, 800)
}

watch(values, debouncedSave, { deep: true })

watch(
  () => props.sessionId,
  (newId) => {
    if (newId && dirty.value) {
      persistValues()
    }
  }
)
</script>

<template>
  <div
    class="flex flex-col h-full surface-card rounded-2xl overflow-hidden border border-light-border/30 dark:border-dark-border/20"
  >
    <div class="px-4 py-3 border-b border-light-border/30 dark:border-dark-border/20">
      <h3 class="text-sm font-semibold txt-primary flex items-center gap-2">
        <Icon icon="heroicons:rectangle-stack" class="w-4 h-4 txt-brand" />
        {{ $t('widgets.customFields.panelTitle') }}
        <Icon
          v-if="saving"
          icon="heroicons:arrow-path"
          class="w-3.5 h-3.5 txt-secondary animate-spin ml-auto"
        />
      </h3>
    </div>

    <div class="flex-1 overflow-y-auto scroll-thin p-4 space-y-4">
      <div v-for="field in customFields" :key="field.id">
        <label class="block text-xs font-medium txt-secondary mb-1.5">
          {{ field.name }}
        </label>
        <input
          v-if="field.type === 'text'"
          v-model="values[field.id]"
          type="text"
          class="w-full px-3 py-2 text-sm rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors"
          maxlength="256"
        />
        <button
          v-else
          class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors w-full"
          :class="
            values[field.id]
              ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
              : 'surface-chip txt-secondary'
          "
          @click="values[field.id] = !values[field.id]"
        >
          <Icon
            :icon="values[field.id] ? 'heroicons:check-circle-solid' : 'heroicons:x-circle'"
            class="w-4 h-4"
          />
          {{ values[field.id] ? $t('common.yes') : $t('common.no') }}
        </button>
      </div>
    </div>
  </div>
</template>
