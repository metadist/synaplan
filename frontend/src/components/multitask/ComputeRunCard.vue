<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { TaskCard } from '@/stores/history'
import { getConfigSync } from '@/services/api/httpClient'

const props = defineProps<{
  card: TaskCard
  isReadonly?: boolean
}>()

const emit = defineEmits<{
  followup: [prompt: string]
}>()

const { t } = useI18n()
const notes = ref('')
const showRerun = ref(false)

const heading = computed(() => {
  if (props.card.state === 'failed') return t('compute.failed')
  if (props.card.state === 'done') return t('compute.done')
  return t('compute.working')
})

const elapsed = computed(() => {
  const seconds = props.card.elapsedSeconds
  return typeof seconds === 'number' && seconds > 0 ? t('compute.elapsed', { seconds }) : ''
})

const isQuota = computed(() => (props.card.error ?? '').includes("week's file-work limit"))

const canRerun = computed(() => !props.isReadonly && ['done', 'failed'].includes(props.card.state))
const showWorkspace = computed(
  () =>
    props.card.usedWorkspace === true &&
    getConfigSync().features?.computeWorkspacesEnabled === true &&
    ['done', 'failed'].includes(props.card.state)
)
const notesId = computed(() => `compute-run-notes-${props.card.nodeId}`)
const notesHelpId = computed(() => `${notesId.value}-help`)

const submitRerun = () => {
  const text = notes.value.trim()
  if ('' === text) return
  emit('followup', text)
  showRerun.value = false
}
</script>

<template>
  <div class="space-y-2" data-testid="compute-run-card">
    <p class="text-sm font-medium txt-primary">{{ heading }}</p>
    <p v-if="elapsed" class="text-xs txt-muted">{{ elapsed }}</p>
    <p
      v-if="card.state === 'failed'"
      class="text-sm txt-muted break-words"
      data-testid="compute-run-error"
    >
      {{ isQuota ? $t('compute.quota') : card.error || $t('taskPlan.failedBody') }}
    </p>
    <p v-else-if="card.text" class="text-sm txt-primary break-words">{{ card.text }}</p>
    <div v-if="card.state === 'done' || showWorkspace" class="flex flex-wrap gap-2">
      <a
        v-if="card.state === 'done' && card.url"
        :href="card.url"
        class="inline-flex items-center gap-1 pill text-xs"
        data-testid="compute-run-preview"
      >
        <Icon icon="mdi:file-outline" class="w-4 h-4" />
        {{ $t('compute.preview') }}
      </a>
      <router-link
        v-if="card.state === 'done'"
        to="/files/generated"
        class="inline-flex items-center gap-1 pill text-xs"
        data-testid="compute-run-generated"
      >
        <Icon icon="mdi:folder-outline" class="w-4 h-4" />
        {{ $t('compute.findInFiles') }}
      </router-link>
      <router-link
        v-if="showWorkspace"
        to="/files/workspace"
        class="inline-flex items-center gap-1 pill text-xs"
        data-testid="compute-run-workspace"
      >
        <Icon icon="mdi:folder-outline" class="w-4 h-4" />
        {{ $t('compute.openWorkspace') }}
      </router-link>
    </div>
    <div v-if="canRerun" class="space-y-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="compute-run-rerun"
        @click="showRerun = !showRerun"
      >
        {{ $t('compute.rerun') }}
      </button>
      <div v-if="showRerun" class="space-y-2">
        <label class="block text-xs txt-muted" :for="notesId">{{ $t('compute.rerunNotes') }}</label>
        <p :id="notesHelpId" class="text-xs txt-muted">{{ $t('compute.rerunHelp') }}</p>
        <textarea
          :id="notesId"
          v-model="notes"
          rows="3"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="compute-run-notes"
          :aria-describedby="notesHelpId"
        />
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!notes.trim()"
          data-testid="compute-run-rerun-submit"
          @click="submitRerun"
        >
          {{ $t('compute.rerun') }}
        </button>
      </div>
    </div>
  </div>
</template>
