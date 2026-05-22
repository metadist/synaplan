<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  XMarkIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
  ClockIcon,
  EnvelopeIcon,
  PaperAirplaneIcon,
  TrashIcon,
  NoSymbolIcon,
  WifiIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import {
  inboundEmailHandlersApi,
  type MailHandlerLogEntry,
  type MailHandlerLogEvent,
  type MailHandlerLogStatus,
} from '@/services/api/inboundEmailHandlersApi'
import { useDateFormat } from '@/composables/useDateFormat'
import { getErrorMessage } from '@/utils/errorMessage'

interface Props {
  isOpen: boolean
  handlerId: string | null
  handlerName?: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const { formatDateTime, formatRelativeTime } = useDateFormat()

const logs = ref<MailHandlerLogEntry[]>([])
const isLoading = ref(false)
const loadError = ref<string | null>(null)

const eventToIcon: Record<MailHandlerLogEvent, typeof CheckCircleIcon> = {
  check: WifiIcon,
  connect_failed: NoSymbolIcon,
  forwarded: PaperAirplaneIcon,
  discarded: TrashIcon,
  no_route: InformationCircleIcon,
  no_smtp: EnvelopeIcon,
  forward_failed: XCircleIcon,
  process_error: ExclamationTriangleIcon,
}

const statusToColor = (status: MailHandlerLogStatus) => {
  if (status === 'error') return 'text-red-500 dark:text-red-400 bg-red-500/10'
  if (status === 'warning') return 'text-amber-500 dark:text-amber-400 bg-amber-500/10'
  return 'text-green-500 dark:text-green-400 bg-green-500/10'
}

const eventLabel = (event: MailHandlerLogEvent): string => t(`mail.activity.events.${event}`, event)

const sortedDetails = (details: Record<string, unknown>): Array<{ key: string; value: string }> => {
  // Show known fields in a stable, useful order; pass through any extras after.
  const order = ['from', 'subject', 'routed_to', 'matched', 'criteria', 'message_number']
  const seen = new Set<string>()
  const rendered: Array<{ key: string; value: string }> = []

  for (const key of order) {
    if (key in details) {
      const value = formatValue(details[key])
      if (value !== '') {
        rendered.push({ key, value })
      }
      seen.add(key)
    }
  }

  for (const [key, value] of Object.entries(details)) {
    if (seen.has(key)) continue
    const formatted = formatValue(value)
    if (formatted !== '') {
      rendered.push({ key, value: formatted })
    }
  }

  return rendered
}

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return ''
  }
}

const detailsLabel = (key: string): string => {
  // Map technical keys to human i18n labels but fall back gracefully.
  const map: Record<string, string> = {
    from: t('mail.activity.fields.from'),
    subject: t('mail.activity.fields.subject'),
    routed_to: t('mail.activity.fields.routedTo'),
    matched: t('mail.activity.fields.matched'),
    criteria: t('mail.activity.fields.criteria'),
    message_number: t('mail.activity.fields.messageNumber'),
  }
  return map[key] ?? key
}

const dateFromTimestamp = (ts: number): Date => new Date(ts * 1000)

async function loadLogs() {
  if (!props.handlerId) return
  isLoading.value = true
  loadError.value = null
  try {
    logs.value = await inboundEmailHandlersApi.getLogs(props.handlerId)
  } catch (err) {
    loadError.value = getErrorMessage(err)
    logs.value = []
  } finally {
    isLoading.value = false
  }
}

watch(
  () => [props.isOpen, props.handlerId] as const,
  ([open, id]) => {
    if (open && id) {
      void loadLogs()
    }
    if (!open) {
      logs.value = []
      loadError.value = null
    }
  },
  { immediate: true }
)

const hasLogs = computed(() => logs.value.length > 0)
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="emit('close')"
      >
        <div
          class="surface-card rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex items-center gap-3 min-w-0">
              <div
                class="w-10 h-10 rounded-xl bg-[var(--brand)]/10 flex items-center justify-center shrink-0"
              >
                <ClockIcon class="w-5 h-5 text-[var(--brand)]" />
              </div>
              <div class="min-w-0">
                <h3 class="text-base sm:text-lg font-semibold txt-primary truncate">
                  {{ t('mail.activity.title') }}
                </h3>
                <p v-if="handlerName" class="text-xs txt-secondary truncate">
                  {{ handlerName }}
                </p>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button
                type="button"
                class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
                :title="t('mail.activity.refresh')"
                :aria-label="t('mail.activity.refresh')"
                :disabled="isLoading"
                @click="loadLogs"
              >
                <ArrowPathIcon
                  class="w-4 h-4 txt-secondary"
                  :class="{ 'animate-spin': isLoading }"
                />
              </button>
              <button
                type="button"
                class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
                :aria-label="t('common.close')"
                @click="emit('close')"
              >
                <XMarkIcon class="w-5 h-5 txt-secondary" />
              </button>
            </div>
          </div>

          <!-- Body -->
          <div class="overflow-y-auto scroll-thin flex-1 px-4 sm:px-6 py-4">
            <p class="text-xs txt-secondary mb-4">{{ t('mail.activity.description') }}</p>

            <!-- Loading state -->
            <div v-if="isLoading && !hasLogs" class="py-12 flex items-center justify-center">
              <ArrowPathIcon class="w-6 h-6 txt-secondary animate-spin" />
            </div>

            <!-- Error state -->
            <div
              v-else-if="loadError"
              class="rounded-xl border border-red-500/20 bg-red-500/5 p-4 flex items-start gap-3"
            >
              <XCircleIcon class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
              <div>
                <p class="text-sm font-medium text-red-500">
                  {{ t('mail.activity.loadFailed') }}
                </p>
                <p class="text-xs txt-secondary mt-1 break-all">{{ loadError }}</p>
              </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="!hasLogs" class="py-12 text-center" data-testid="mail-activity-empty">
              <ClockIcon class="w-10 h-10 txt-secondary/40 mx-auto mb-3" />
              <p class="text-sm font-medium txt-primary">{{ t('mail.activity.empty') }}</p>
              <p class="text-xs txt-secondary mt-1">{{ t('mail.activity.emptyDescription') }}</p>
            </div>

            <!-- Log entries -->
            <ul v-else class="space-y-3" data-testid="mail-activity-list">
              <li
                v-for="entry in logs"
                :key="entry.id"
                class="rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 flex gap-3"
              >
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                  :class="statusToColor(entry.status)"
                >
                  <component
                    :is="eventToIcon[entry.event] ?? InformationCircleIcon"
                    class="w-4 h-4"
                  />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between gap-2 flex-wrap">
                    <span class="text-sm font-medium txt-primary">
                      {{ eventLabel(entry.event) }}
                    </span>
                    <span
                      class="text-[11px] txt-secondary"
                      :title="formatDateTime(dateFromTimestamp(entry.timestamp))"
                    >
                      {{ formatRelativeTime(dateFromTimestamp(entry.timestamp)) }}
                    </span>
                  </div>

                  <p
                    v-if="entry.error"
                    class="text-xs text-red-500 dark:text-red-400 mt-1 break-words"
                  >
                    {{ entry.error }}
                  </p>

                  <dl
                    v-if="sortedDetails(entry.details).length > 0"
                    class="mt-2 grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs"
                  >
                    <template v-for="item in sortedDetails(entry.details)" :key="item.key">
                      <dt class="txt-secondary">{{ detailsLabel(item.key) }}</dt>
                      <dd class="txt-primary break-words">{{ item.value }}</dd>
                    </template>
                  </dl>
                </div>
              </li>
            </ul>
          </div>

          <!-- Footer -->
          <div
            class="flex items-center justify-between gap-2 p-4 sm:p-5 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <p class="text-xs txt-secondary">
              {{ t('mail.activity.retentionNote') }}
            </p>
            <button
              type="button"
              class="px-4 py-2 rounded-lg surface-chip txt-secondary hover:txt-primary transition-colors text-sm font-medium"
              @click="emit('close')"
            >
              {{ t('common.close') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
