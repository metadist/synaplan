<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
      @click.self="$emit('close')"
    >
      <div class="w-full max-w-md surface-card rounded-xl overflow-hidden">
        <!-- Header -->
        <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:arrow-down-tray" class="w-5 h-5 txt-brand" />
              {{ $t('export.title') }}
            </h2>
            <button
              class="p-2 rounded-lg hover-surface transition-colors"
              @click="$emit('close')"
            >
              <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="p-4 space-y-4">
          <!-- Format Selection -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('export.format') }}
            </label>
            <div class="space-y-2">
              <label
                v-for="format in formats"
                :key="format.id"
                :class="[
                  'flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-colors',
                  selectedFormat === format.id
                    ? 'bg-[var(--brand-alpha-light)] border-2 border-[var(--brand)]'
                    : 'surface-chip hover:bg-black/5 dark:hover:bg-white/5 border-2 border-transparent',
                ]"
              >
                <input
                  v-model="selectedFormat"
                  type="radio"
                  :value="format.id"
                  class="mt-1"
                />
                <div class="flex-1">
                  <div class="flex items-center gap-2">
                    <span class="font-medium txt-primary">{{ format.name }}</span>
                    <span
                      v-if="format.recommended"
                      class="px-1.5 py-0.5 rounded text-xs font-medium bg-green-500/10 text-green-600"
                    >
                      {{ $t('export.recommended') }}
                    </span>
                  </div>
                  <p class="text-xs txt-secondary mt-0.5">{{ format.description }}</p>
                </div>
              </label>
            </div>
          </div>

          <!-- Date Range -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('export.dateRange') }}
            </label>
            <select
              v-model="dateRange"
              class="w-full px-3 py-2 rounded-lg surface-chip txt-primary"
            >
              <option value="all">{{ $t('export.allTime') }}</option>
              <option value="today">{{ $t('export.today') }}</option>
              <option value="7days">{{ $t('export.last7Days') }}</option>
              <option value="30days">{{ $t('export.last30Days') }}</option>
              <option value="custom">{{ $t('export.custom') }}</option>
            </select>

            <div v-if="dateRange === 'custom'" class="mt-2 flex gap-2">
              <input
                v-model="customFrom"
                type="date"
                class="flex-1 px-3 py-2 rounded-lg surface-chip txt-primary"
              />
              <span class="self-center txt-secondary">-</span>
              <input
                v-model="customTo"
                type="date"
                class="flex-1 px-3 py-2 rounded-lg surface-chip txt-primary"
              />
            </div>
          </div>

          <!-- Mode Filter -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('export.sessionType') }}
            </label>
            <select
              v-model="modeFilter"
              class="w-full px-3 py-2 rounded-lg surface-chip txt-primary"
            >
              <option value="">{{ $t('export.allSessions') }}</option>
              <option value="ai">{{ $t('export.aiOnly') }}</option>
              <option value="human">{{ $t('export.humanOnly') }}</option>
            </select>
          </div>
        </div>

        <!-- Footer -->
        <div class="p-4 border-t border-light-border/30 dark:border-dark-border/20 flex justify-end gap-2">
          <button
            class="px-4 py-2 rounded-lg surface-chip txt-secondary hover-surface transition-colors"
            @click="$emit('close')"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            :disabled="exporting"
            class="px-4 py-2 rounded-lg btn-primary disabled:opacity-50"
            @click="startExport"
          >
            <Icon
              v-if="exporting"
              icon="heroicons:arrow-path"
              class="w-4 h-4 inline mr-1 animate-spin"
            />
            {{ exporting ? $t('export.exporting') : $t('export.download') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  widgetId: string
}>()

defineEmits<{
  (e: 'close'): void
}>()

const { t } = useI18n()
const { error } = useNotification()
const configStore = useConfigStore()

const formats = ref<widgetSessionsApi.ExportFormat[]>([
  { id: 'xlsx', name: 'Excel (XLSX)', description: 'Best for human readability', recommended: true },
  { id: 'csv', name: 'CSV', description: 'Simple format for spreadsheets', recommended: false },
  { id: 'json', name: 'JSON', description: 'For developers and data analysis', recommended: false },
])
const selectedFormat = ref<'xlsx' | 'csv' | 'json'>('xlsx')
const dateRange = ref('all')
const customFrom = ref('')
const customTo = ref('')
const modeFilter = ref('')
const exporting = ref(false)

const loadFormats = async () => {
  try {
    const response = await widgetSessionsApi.getExportFormats(props.widgetId)
    formats.value = response.formats
  } catch (err) {
    // Use defaults
  }
}

const getDateTimestamps = computed(() => {
  const now = Math.floor(Date.now() / 1000)
  const daySeconds = 86400

  switch (dateRange.value) {
    case 'today':
      return {
        from: now - daySeconds,
        to: now,
      }
    case '7days':
      return {
        from: now - 7 * daySeconds,
        to: now,
      }
    case '30days':
      return {
        from: now - 30 * daySeconds,
        to: now,
      }
    case 'custom':
      return {
        from: customFrom.value ? Math.floor(new Date(customFrom.value).getTime() / 1000) : undefined,
        to: customTo.value ? Math.floor(new Date(customTo.value).getTime() / 1000) + daySeconds : undefined,
      }
    default:
      return {}
  }
})

const startExport = async () => {
  exporting.value = true

  try {
    const params: widgetSessionsApi.ExportParams = {
      format: selectedFormat.value,
      ...getDateTimestamps.value,
    }

    if (modeFilter.value) {
      params.mode = modeFilter.value as 'ai' | 'human'
    }

    const exportUrl = widgetSessionsApi.getExportUrl(props.widgetId, params)
    const fullUrl = `${configStore.apiBaseUrl}${exportUrl}`

    // Trigger download
    const link = document.createElement('a')
    link.href = fullUrl
    link.download = ''
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  } catch (err: any) {
    error(err.message || 'Export failed')
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  loadFormats()
})
</script>
