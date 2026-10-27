<template>
  <form class="space-y-3" data-testid="form-notion-watch" @submit.prevent="submit">
    <label class="block">
      <span class="text-sm txt-primary">{{ $t('apps.notion.watches.pageLabel') }}</span>
      <input
        v-model="page"
        type="url"
        required
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :placeholder="$t('apps.notion.watches.pagePlaceholder')"
        :disabled="saving"
        data-testid="input-notion-watch-page"
      />
    </label>

    <label class="block">
      <span class="text-sm txt-primary">{{ $t('apps.notion.watches.actionLabel') }}</span>
      <select
        v-model="action"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving"
        data-testid="select-notion-watch-action"
      >
        <option value="searchable">{{ $t('apps.notion.watches.actionSearchable') }}</option>
        <option value="task">{{ $t('apps.notion.watches.actionTask') }}</option>
      </select>
    </label>

    <label v-if="action === 'searchable'" class="block">
      <span class="text-sm txt-primary">{{ $t('apps.notion.watches.folderLabel') }}</span>
      <input
        v-model="folder"
        type="text"
        maxlength="64"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving"
        data-testid="input-notion-watch-folder"
      />
    </label>

    <template v-else>
      <label v-if="tasks.length > 0" class="block">
        <span class="text-sm txt-primary">{{ $t('apps.notion.watches.taskLabel') }}</span>
        <select
          v-model="taskId"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="saving"
          data-testid="select-notion-watch-task"
        >
          <option v-for="task in tasks" :key="task.id" :value="task.id">{{ task.name }}</option>
        </select>
      </label>
      <p v-else class="txt-secondary text-sm" data-testid="text-notion-watch-no-tasks">
        {{ $t('apps.notion.watches.noTasks') }}
        <router-link to="/tasks" class="txt-brand hover:underline">
          {{ $t('apps.notion.watches.openTasks') }}
        </router-link>
      </p>
    </template>

    <button
      type="submit"
      class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="!canSubmit"
      data-testid="btn-notion-watch-add"
    >
      {{ $t('apps.notion.watches.add') }}
    </button>
  </form>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { NotionWatch, NotionWatchInput } from '@/services/api/notionAppApi'

const props = defineProps<{
  tasks: { id: number; name: string }[]
  saving: boolean
}>()

const emit = defineEmits<{ submit: [input: NotionWatchInput] }>()

const page = ref('')
const action = ref<NotionWatch['action']>('searchable')
const folder = ref('Notion')
const taskId = ref<number | null>(props.tasks[0]?.id ?? null)

watch(
  () => props.tasks,
  (tasks) => {
    if (taskId.value === null || !tasks.some((task) => task.id === taskId.value)) {
      taskId.value = tasks[0]?.id ?? null
    }
  }
)

const canSubmit = computed(
  () =>
    !props.saving &&
    page.value.trim() !== '' &&
    (action.value === 'searchable' || taskId.value !== null)
)

const submit = () => {
  if (!canSubmit.value) return
  emit(
    'submit',
    action.value === 'searchable'
      ? { page: page.value.trim(), action: 'searchable', folder: folder.value.trim() || null }
      : { page: page.value.trim(), action: 'task', task_id: taskId.value }
  )
}

const reset = () => {
  page.value = ''
}

defineExpose({ reset })
</script>
