<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-triggers">
    <h2 class="txt-primary font-medium">{{ $t('assistants.triggers.title') }}</h2>
    <p class="txt-secondary text-sm">
      {{
        $t('assistants.triggers.question', {
          name: store.current?.name || $t('assistants.untitled'),
        })
      }}
    </p>

    <div
      class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 px-3 py-2"
      data-testid="row-trigger-chat"
    >
      <p class="txt-primary text-sm">{{ $t('assistants.triggers.alwaysOn') }}</p>
      <span class="txt-secondary text-sm">{{ $t('assistants.triggers.alwaysOnBadge') }}</span>
    </div>

    <div v-if="isEmpty" class="space-y-3" data-testid="state-triggers-empty">
      <p class="txt-secondary text-sm">{{ $t('assistants.triggers.empty') }}</p>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-add-event-empty"
          @click="picker = 'event'"
        >
          {{ $t('assistants.triggers.addEvent') }}
        </button>
        <button
          v-if="savedTasksEnabled"
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-add-schedule-empty"
          @click="picker = 'schedule'"
        >
          {{ $t('assistants.triggers.addSchedule') }}
        </button>
      </div>
    </div>

    <div v-else class="space-y-4">
      <div class="flex items-center justify-between gap-2">
        <h3 class="txt-primary text-sm font-medium">{{ $t('assistants.triggers.events') }}</h3>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-add-event"
          @click="picker = 'event'"
        >
          {{ $t('assistants.triggers.addEvent') }}
        </button>
      </div>
      <ul class="space-y-2">
        <li
          v-for="event in events"
          :key="event.id"
          class="rounded-lg border border-light-border/30 dark:border-dark-border/20 p-3 space-y-1"
          :data-testid="`row-event-${event.id}`"
        >
          <p class="txt-primary text-sm">{{ sentenceForEvent(event) }}</p>
          <p class="txt-secondary text-sm">{{ runsAsLabel(event.kind) }}</p>
          <div class="flex flex-wrap items-center gap-2">
            <label class="inline-flex items-center gap-2 text-sm txt-primary">
              <input
                type="checkbox"
                :checked="event.enabled !== false"
                @change="toggleEvent(event.id, ($event.target as HTMLInputElement).checked)"
              />
              {{ $t('assistants.triggers.on') }}
            </label>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
              @click="removeEvent(event.id)"
            >
              {{ $t('assistants.triggers.remove') }}
            </button>
          </div>
        </li>
      </ul>

      <div v-if="savedTasksEnabled" class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <h3 class="txt-primary text-sm font-medium">{{ $t('assistants.triggers.schedule') }}</h3>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-add-schedule"
            @click="picker = 'schedule'"
          >
            {{ $t('assistants.triggers.addSchedule') }}
          </button>
        </div>
        <ul class="space-y-2">
          <li
            v-for="item in schedules"
            :key="item.id"
            class="rounded-lg border border-light-border/30 dark:border-dark-border/20 p-3 space-y-1"
            :data-testid="`row-schedule-${item.id}`"
          >
            <p class="txt-primary text-sm">{{ sentenceForSchedule(item) }}</p>
            <p class="txt-secondary text-sm">{{ $t('assistants.triggers.runsAsYou') }}</p>
            <p class="txt-secondary text-sm">
              {{ $t('assistants.triggers.savedUnderAutomations') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <label class="inline-flex items-center gap-2 text-sm txt-primary">
                <input
                  type="checkbox"
                  :checked="item.enabled !== false"
                  @change="toggleSchedule(item.id, ($event.target as HTMLInputElement).checked)"
                />
                {{ $t('assistants.triggers.on') }}
              </label>
              <button
                type="button"
                class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
                @click="removeSchedule(item.id)"
              >
                {{ $t('assistants.triggers.remove') }}
              </button>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <div
      v-if="picker === 'event'"
      class="rounded-lg border border-light-border/30 dark:border-dark-border/20 p-4 space-y-3"
      data-testid="picker-add-event"
    >
      <p class="txt-primary text-sm font-medium">{{ $t('assistants.triggers.whatReacts') }}</p>
      <button
        v-if="availableKinds.includes('mail')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-mail"
        @click="eventKind = 'mail'"
      >
        {{ $t('assistants.triggers.kindMail') }}
      </button>
      <button
        v-if="availableKinds.includes('widget')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-widget"
        @click="addWidgetEvent"
      >
        {{ $t('assistants.triggers.kindWidget') }}
      </button>
      <button
        v-if="availableKinds.includes('whatsapp')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-whatsapp"
        @click="addWhatsappEvent"
      >
        {{ $t('assistants.triggers.kindWhatsapp') }}
      </button>
      <button
        v-if="availableKinds.includes('api')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-api"
        @click="addSimpleEvent('api')"
      >
        {{ $t('assistants.triggers.kindApi') }}
      </button>
      <button
        v-if="availableKinds.includes('mcp')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-mcp"
        @click="addSimpleEvent('mcp')"
      >
        {{ $t('assistants.triggers.kindMcp') }}
      </button>
      <button
        v-if="availableKinds.includes('desktop')"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium w-full text-left"
        data-testid="btn-kind-desktop"
        @click="addSimpleEvent('desktop')"
      >
        {{ $t('assistants.triggers.kindDesktop') }}
      </button>
      <AddEventMailForm v-if="eventKind === 'mail'" @cancel="eventKind = ''" @save="addEvent" />
      <button
        v-if="eventKind !== 'mail'"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        @click="picker = ''"
      >
        {{ $t('common.cancel') }}
      </button>
    </div>

    <AddScheduleForm v-if="picker === 'schedule'" @cancel="picker = ''" @save="addSchedule" />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { agentsApi, emptyAgentDraft, type AgentDraft } from '@/services/api/agentsApi'
import { listWidgets } from '@/services/api/widgetsApi'
import { useAgentsStore } from '@/stores/agents'
import { useAuthStore } from '@/stores/auth'
import AddEventMailForm from './AddEventMailForm.vue'
import AddScheduleForm from './AddScheduleForm.vue'

type TriggerEvent = AgentDraft['triggers']['events'][number]
type TriggerSchedule = AgentDraft['triggers']['schedules'][number]
type EventKind = NonNullable<TriggerEvent['kind']>

const store = useAgentsStore()
const auth = useAuthStore()
const { t } = useI18n()
const picker = ref('')
const eventKind = ref('')
const savedTasksEnabled = ref(true)
const availableKinds = ref<string[]>(['mail', 'widget', 'whatsapp', 'api', 'mcp', 'desktop'])

const events = computed((): TriggerEvent[] => store.current?.draft?.triggers.events ?? [])
const schedules = computed((): TriggerSchedule[] => store.current?.draft?.triggers.schedules ?? [])
const isEmpty = computed(() => events.value.length === 0 && schedules.value.length === 0)

function patchTriggers(next: AgentDraft['triggers']): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = { ...draft, triggers: next }
  store.markDirty()
}

function addEvent(payload: Record<string, unknown>): void {
  patchTriggers({
    events: [...events.value, payload as TriggerEvent],
    schedules: schedules.value,
  })
  picker.value = ''
  eventKind.value = ''
}

function addSchedule(payload: Record<string, unknown>): void {
  patchTriggers({
    events: events.value,
    schedules: [...schedules.value, payload as TriggerSchedule],
  })
  picker.value = ''
}

function toggleEvent(id: string | undefined, enabled: boolean): void {
  if (!id) {
    return
  }
  patchTriggers({
    events: events.value.map((event) => (event.id === id ? { ...event, enabled } : event)),
    schedules: schedules.value,
  })
}

function toggleSchedule(id: string | undefined, enabled: boolean): void {
  if (!id) {
    return
  }
  patchTriggers({
    events: events.value,
    schedules: schedules.value.map((item) => (item.id === id ? { ...item, enabled } : item)),
  })
}

function removeEvent(id: string | undefined): void {
  if (!id) {
    return
  }
  patchTriggers({
    events: events.value.filter((event) => event.id !== id),
    schedules: schedules.value,
  })
}

function removeSchedule(id: string | undefined): void {
  if (!id) {
    return
  }
  patchTriggers({
    events: events.value,
    schedules: schedules.value.filter((item) => item.id !== id),
  })
}

async function addWidgetEvent(): Promise<void> {
  const ownerId = auth.user?.id
  try {
    const widgets = await listWidgets()
    const first = widgets[0]
    if (!first || !ownerId) {
      picker.value = ''
      return
    }
    addEvent({
      id: `wdg-${Math.random().toString(36).slice(2, 8)}`,
      kind: 'widget',
      widget: `${ownerId}:${first.widgetId}`,
      enabled: true,
    })
  } catch {
    picker.value = ''
  }
}

function addWhatsappEvent(): void {
  addSimpleEvent('whatsapp')
}

function addSimpleEvent(kind: EventKind): void {
  const prefix = kind === 'whatsapp' ? 'wa' : kind
  addEvent({
    id: `${prefix}-${Math.random().toString(36).slice(2, 8)}`,
    kind,
    enabled: true,
  })
}

function sentenceForEvent(event: TriggerEvent): string {
  if (event.kind === 'mail') {
    return t('assistants.triggers.sentenceMail', { mailbox: String(event.mailbox ?? '') })
  }
  if (event.kind === 'widget') {
    return t('assistants.triggers.sentenceWidget', { widget: String(event.widget ?? '') })
  }
  if (event.kind === 'whatsapp') {
    return t('assistants.triggers.sentenceWhatsapp')
  }
  if (event.kind === 'api') {
    return t('assistants.triggers.sentenceApi', { slug: store.current?.slug ?? '' })
  }
  if (event.kind === 'mcp') {
    return t('assistants.triggers.sentenceMcp')
  }
  if (event.kind === 'desktop') {
    return t('assistants.triggers.sentenceDesktop')
  }
  return t('assistants.triggers.sentenceEvent')
}

const WEEKDAYS = [
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
] as const

function weekdayLabel(day: string | undefined): string {
  const known = WEEKDAYS.find((weekday) => weekday === day) ?? 'monday'

  return t(`assistants.triggers.${known}`)
}

function sentenceForSchedule(item: TriggerSchedule): string {
  const every = item.every as { unit?: string; on?: string; at?: string } | undefined
  if (every?.unit === 'week') {
    return t('assistants.triggers.sentenceWeekly', {
      day: weekdayLabel(every.on),
      at: every.at ?? '08:00',
    })
  }
  return t('assistants.triggers.sentenceSchedule', {
    name: String(item.name ?? item.instruction ?? ''),
  })
}

function runsAsLabel(kind: EventKind | undefined): string {
  if (kind === 'mail' || kind === 'api') {
    return t('assistants.triggers.runsAsYou')
  }
  if (kind === 'mcp' || kind === 'desktop') {
    return t('assistants.triggers.runsAsAppUser')
  }
  return t('assistants.triggers.runsAsPerson')
}

onMounted(async () => {
  const id = store.current?.id
  if (id == null) {
    return
  }
  try {
    const resolved = await agentsApi.triggers(id)
    savedTasksEnabled.value = resolved.savedTasksEnabled
    availableKinds.value = resolved.availableKinds
  } catch {
    savedTasksEnabled.value = true
  }
})
</script>
