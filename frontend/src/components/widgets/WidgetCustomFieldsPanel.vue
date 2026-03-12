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
const { success, error: showError } = useNotification()

const values = ref<Record<string, string | boolean>>({})
const saving = ref(false)
const saved = ref(false)

const initValues = () => {
  const v: Record<string, string | boolean> = {}
  for (const field of props.customFields) {
    v[field.id] = values.value[field.id] ?? (field.type === 'boolean' ? false : '')
  }
  values.value = v
}

initValues()

watch(() => props.customFields, initValues, { deep: true })

const handleSave = async () => {
  if (!props.sessionId) return
  saving.value = true
  saved.value = false
  try {
    await saveCustomFieldValues(props.widgetId, props.sessionId, values.value)
    saved.value = true
    success(t('widgets.customFields.saved'))
    setTimeout(() => {
      saved.value = false
    }, 3000)
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : t('widgets.customFields.saveFailed')
    showError(message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    class="flex flex-col h-full surface-card rounded-2xl overflow-hidden border border-light-border/30 dark:border-dark-border/20"
  >
    <div class="px-4 py-3 border-b border-light-border/30 dark:border-dark-border/20">
      <h3 class="text-sm font-semibold txt-primary flex items-center gap-2">
        <Icon icon="heroicons:rectangle-stack" class="w-4 h-4 txt-brand" />
        {{ $t('widgets.customFields.panelTitle') }}
      </h3>
    </div>

    <div class="flex-1 overflow-y-auto scroll-thin p-4 space-y-4">
      <div v-if="!sessionId" class="text-center py-6">
        <Icon icon="heroicons:chat-bubble-left-right" class="w-8 h-8 txt-secondary mx-auto mb-2" />
        <p class="text-xs txt-secondary">{{ $t('widgets.customFields.noSession') }}</p>
      </div>

      <template v-else>
        <div v-for="field in customFields" :key="field.id">
          <label class="block text-xs font-medium txt-secondary mb-1.5">
            {{ field.name }}
          </label>
          <input
            v-if="field.type === 'text'"
            v-model="values[field.id]"
            type="text"
            class="w-full px-3 py-2 text-sm rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors"
            maxlength="1000"
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
      </template>
    </div>

    <div
      v-if="sessionId"
      class="px-4 py-3 border-t border-light-border/30 dark:border-dark-border/20"
    >
      <button
        class="btn-primary w-full py-2 text-sm rounded-lg flex items-center justify-center gap-2 disabled:opacity-50"
        :disabled="saving"
        @click="handleSave"
      >
        <Icon v-if="saving" icon="heroicons:arrow-path" class="w-4 h-4 animate-spin" />
        <Icon v-else-if="saved" icon="heroicons:check" class="w-4 h-4" />
        <Icon v-else icon="heroicons:arrow-down-tray" class="w-4 h-4" />
        {{ $t('widgets.customFields.save') }}
      </button>
    </div>
  </div>
</template>
