<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import SavedTaskCard from '@/components/config/SavedTaskCard.vue'
import { savedTasksApi, type SavedTask } from '@/services/api/savedTasksApi'

const { t } = useI18n()
const { error: showError } = useNotification()

const tasks = ref<SavedTask[]>([])
const loading = ref(true)

const load = async () => {
  loading.value = true
  try {
    tasks.value = await savedTasksApi.list()
  } catch {
    showError(t('config.savedTasks.loadFailed'))
    tasks.value = []
  } finally {
    loading.value = false
  }
}

const onUpdated = (task: SavedTask) => {
  tasks.value = tasks.value.map((row) => (row.id === task.id ? task : row))
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-6" data-testid="page-saved-tasks">
    <div class="surface-card p-6">
      <h2 class="text-2xl font-semibold txt-primary">
        {{ $t('config.savedTasks.overviewTitle') }}
      </h2>
      <p class="txt-secondary text-sm mt-1">{{ $t('config.savedTasks.overviewSubtitle') }}</p>
    </div>

    <p v-if="loading" class="txt-secondary text-sm px-1">
      {{ $t('config.savedTasks.overviewLoading') }}
    </p>

    <p
      v-else-if="tasks.length === 0"
      class="txt-secondary text-sm px-1"
      data-testid="saved-tasks-empty"
    >
      {{ $t('config.savedTasks.overviewEmpty') }}
      <RouterLink to="/ai/instructions" class="txt-primary underline">
        {{ $t('nav.configTaskPrompts') }}
      </RouterLink>
    </p>

    <ul v-else class="space-y-4">
      <li v-for="task in tasks" :key="task.id">
        <SavedTaskCard :task="task" @updated="onUpdated" />
      </li>
    </ul>
  </div>
</template>
