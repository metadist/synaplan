<template>
  <Teleport :to="teleportTarget">
    <div
      v-if="open"
      class="modal-overlay fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
      @click.self="close"
    >
      <form
        class="modal-panel surface-card rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto scroll-thin"
        role="dialog"
        aria-modal="true"
        aria-labelledby="new-task-title"
        data-testid="modal-new-task"
        @submit.prevent="submit"
        @keydown.esc="close"
      >
        <div
          class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
        >
          <h3 id="new-task-title" class="text-lg font-semibold txt-primary">
            {{ $t('config.savedTasks.newTask.title') }}
          </h3>
          <button
            type="button"
            class="icon-ghost w-8 h-8 rounded-xl flex items-center justify-center"
            :aria-label="$t('common.close')"
            @click="close"
          >
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-4 sm:p-6 space-y-4">
          <label class="block text-sm font-medium txt-primary">
            {{ $t('config.savedTasks.newTask.name') }}
            <input
              v-model="name"
              required
              maxlength="120"
              :placeholder="$t('config.savedTasks.newTask.namePlaceholder')"
              class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-new-task-name"
            />
          </label>

          <label class="block text-sm font-medium txt-primary">
            {{ $t('config.savedTasks.newTask.instruction') }}
            <textarea
              v-model="instruction"
              required
              rows="5"
              :placeholder="$t('config.savedTasks.newTask.instructionPlaceholder')"
              class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-new-task-instruction"
            />
          </label>

          <div class="flex flex-wrap gap-3">
            <label class="block flex-1 min-w-[10rem] text-sm font-medium txt-primary">
              {{ $t('config.savedTasks.newTask.schedule') }}
              <select
                v-model="schedule"
                class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="select-new-task-schedule"
              >
                <option value="off">{{ $t('config.savedTasks.schedule.off') }}</option>
                <option value="interval">{{ $t('config.savedTasks.schedule.hourly') }}</option>
                <option value="daily">{{ $t('config.savedTasks.schedule.daily') }}</option>
                <option value="weekly">{{ $t('config.savedTasks.schedule.weekdays') }}</option>
              </select>
            </label>
            <label
              v-if="schedule === 'daily' || schedule === 'weekly'"
              class="block w-32 text-sm font-medium txt-primary"
            >
              {{ $t('config.savedTasks.newTask.at') }}
              <input
                v-model="at"
                type="time"
                required
                class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-new-task-at"
              />
            </label>
          </div>
          <p class="text-xs txt-secondary">{{ $t('config.savedTasks.newTask.hint') }}</p>
        </div>

        <div
          class="flex justify-end gap-2 p-4 sm:p-6 border-t border-light-border/10 dark:border-dark-border/10"
        >
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-new-task-cancel"
            @click="close"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="submit"
            class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="saving || !name.trim() || !instruction.trim()"
            data-testid="btn-new-task-create"
          >
            {{ $t('config.savedTasks.newTask.create') }}
          </button>
        </div>
      </form>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { ensureAccountTimezone } from '@/composables/useAccountTimezone'
import { promptsApi } from '@/services/api/promptsApi'
import { savedTasksApi, type SavedTask } from '@/services/api/savedTasksApi'

type ScheduleKind = 'off' | 'interval' | 'daily' | 'weekly'

const HOURLY_MINUTES = 60
const WEEKDAYS = [1, 2, 3, 4, 5]
const DEFAULT_AT = '07:00'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  close: []
  created: [task: SavedTask]
}>()

const { t, locale } = useI18n()
const { success, error } = useNotification()
const { teleportTarget } = useFullscreenTeleportTarget()

const name = ref('')
const instruction = ref('')
const schedule = ref<ScheduleKind>('off')
const at = ref(DEFAULT_AT)
const saving = ref(false)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    name.value = ''
    instruction.value = ''
    schedule.value = 'off'
    at.value = DEFAULT_AT
  }
)

function close(): void {
  if (!saving.value) emit('close')
}

/** Unique per task so a second task with the same name never collides with the first topic. */
function taskTopic(taskName: string): string {
  const slug = taskName
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
  return `task-${slug || 'custom'}-${Date.now().toString(36)}`
}

async function applySchedule(task: SavedTask): Promise<SavedTask> {
  if (schedule.value === 'off') return task
  const triggerConfig: Record<string, unknown> = { kind: schedule.value }
  if (schedule.value === 'interval') {
    triggerConfig.every_minutes = HOURLY_MINUTES
  } else {
    const zone = await ensureAccountTimezone()
    if (!zone) throw new Error('timezone')
    triggerConfig.at = at.value
    triggerConfig.tz = zone.tz
    if (schedule.value === 'weekly') triggerConfig.days = WEEKDAYS
  }
  return savedTasksApi.update(task.id, {
    triggerType: 'schedule',
    triggerConfig,
    allowUnattended: true,
  })
}

async function submit(): Promise<void> {
  const taskName = name.value.trim()
  const text = instruction.value.trim()
  if (!taskName || !text || saving.value) return
  saving.value = true

  let promptId: number | null = null
  try {
    const prompt = await promptsApi.createPrompt({
      topic: taskTopic(taskName),
      shortDescription: taskName,
      prompt: text,
      language: locale.value || 'en',
      // The router must never pick this topic for an ordinary chat message.
      selectionRules: `Only for the saved task "${taskName}". Never select this topic for a chat message.`,
      metadata: { aiModel: 0, tool_files: true, tool_url_screenshot: false, tool_mcp: false },
    })
    promptId = prompt.id
    const task = await savedTasksApi.create(prompt.id, taskName)
    promptId = null

    try {
      emit('created', await applySchedule(task))
      success(t('config.savedTasks.newTask.created'))
    } catch {
      emit('created', task)
      error(t('config.savedTasks.newTask.createdWithoutSchedule'))
    }
    emit('close')
  } catch {
    if (promptId !== null) {
      await promptsApi.deletePrompt(promptId).catch(() => undefined)
    }
    error(t('config.savedTasks.newTask.failed'))
  } finally {
    saving.value = false
  }
}
</script>
