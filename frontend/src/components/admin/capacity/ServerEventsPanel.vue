<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminLogsApi, type RingEvent } from '@/services/api/adminLogsApi'

/**
 * The last hour of warnings one server produced, read from the event ring
 * through the authenticated client (the native app has no cookie session, so
 * a plain link to the API would answer 401). Loads on first open.
 */
const props = defineProps<{
  host: string
}>()

const { t, locale } = useI18n()

const open = ref(false)
const loading = ref(false)
const failed = ref(false)
const events = ref<RingEvent[] | null>(null)

const WINDOW_MINUTES = 60
const LIMIT = 20

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    events.value = await adminLogsApi.recent({
      host: props.host,
      sinceMinutes: WINDOW_MINUTES,
      limit: LIMIT,
    })
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function toggle(): void {
  open.value = !open.value
  if (open.value && events.value === null && !loading.value) {
    void load()
  }
}

const timeFormat = computed(() => new Intl.DateTimeFormat(locale.value, { timeStyle: 'medium' }))

function at(event: RingEvent): string {
  return typeof event.ts === 'number' ? timeFormat.value.format(new Date(event.ts * 1000)) : ''
}

function levelClass(level: string | undefined): string {
  switch (level) {
    case 'error':
    case 'critical':
    case 'alert':
    case 'emergency':
      return 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    default:
      return 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
  }
}
</script>

<template>
  <div class="space-y-2" data-testid="panel-server-events">
    <button
      type="button"
      class="inline-flex items-center gap-1 text-sm text-[var(--brand)] hover:underline"
      :aria-expanded="open"
      data-testid="btn-recent-events"
      @click="toggle"
    >
      {{ open ? t('capacity.events.hide') : t('capacity.events.open') }}
    </button>

    <div
      v-if="open"
      class="surface-chip rounded-xl p-3 text-sm space-y-2"
      data-testid="list-server-events"
    >
      <p v-if="loading" class="txt-secondary">{{ t('capacity.events.loading') }}</p>
      <p v-else-if="failed" class="txt-primary">
        {{ t('capacity.events.failed') }}
        <button type="button" class="ml-2 text-[var(--brand)] hover:underline" @click="load">
          {{ t('capacity.checkAgain') }}
        </button>
      </p>
      <p
        v-else-if="events && events.length === 0"
        class="txt-secondary"
        data-testid="text-events-empty"
      >
        {{ t('capacity.events.empty') }}
      </p>
      <ul v-else-if="events" class="space-y-1.5">
        <li
          v-for="(event, index) in events"
          :key="event.id ?? index"
          class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5"
          data-testid="item-server-event"
        >
          <span class="tabular-nums txt-secondary text-xs">{{ at(event) }}</span>
          <span
            class="px-1.5 py-0.5 rounded-full text-[0.65rem] font-semibold uppercase"
            :class="levelClass(event.level)"
          >
            {{ event.level }}
          </span>
          <span class="txt-primary break-words">{{ event.message || event.event }}</span>
          <span v-if="event.route" class="txt-secondary text-xs">{{ event.route }}</span>
        </li>
      </ul>
      <p class="txt-secondary text-xs">{{ t('capacity.events.window') }}</p>
    </div>
  </div>
</template>
