<template>
  <li
    class="flex items-start gap-3 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 px-3 py-2.5"
    :data-testid="`item-project-source-${source.id}`"
    :data-state="source.state"
  >
    <component
      :is="kindIcon"
      class="mt-0.5 h-5 w-5 flex-shrink-0 txt-secondary"
      aria-hidden="true"
    />

    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-medium txt-primary" :title="source.name">
        {{ source.name }}
      </p>
      <p v-if="linkHref" class="truncate text-xs txt-secondary">
        <a
          :href="linkHref"
          target="_blank"
          rel="noopener noreferrer"
          class="hover:underline"
          data-testid="link-project-source-url"
          >{{ source.sourceUrl }}</a
        >
      </p>

      <p
        v-if="source.state === 'reading'"
        class="mt-0.5 flex items-center gap-1.5 text-xs txt-secondary"
        role="status"
        data-testid="text-project-source-reading"
      >
        <Icon icon="mdi:loading" class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        {{ $t('projects.source.reading') }}
      </p>
      <p
        v-else-if="source.state === 'ready'"
        class="mt-0.5 flex items-center gap-1.5 text-xs txt-secondary"
        data-testid="text-project-source-ready"
      >
        <CheckCircleIcon class="h-3.5 w-3.5 text-success" aria-hidden="true" />
        {{ $t('projects.source.ready')
        }}<template v-if="source.size > 0"> · {{ sizeLabel }}</template>
      </p>
      <div v-else class="mt-0.5" role="alert" data-testid="text-project-source-failed">
        <p class="text-xs font-medium text-red-600 dark:text-red-400">
          {{ $t('projects.source.failed') }}
        </p>
        <p v-if="source.reason" class="text-xs txt-secondary break-words">
          {{ source.reason }}
        </p>
      </div>
    </div>

    <div class="flex flex-shrink-0 items-center gap-1">
      <button
        v-if="canRetry"
        type="button"
        class="btn-secondary px-3 py-1.5 text-xs font-medium min-h-9"
        :disabled="busy"
        data-testid="btn-project-source-retry"
        @click="emit('retry', source.id)"
      >
        {{ $t('projects.source.retry') }}
      </button>
      <button
        type="button"
        class="icon-ghost inline-flex h-9 w-9 items-center justify-center rounded-lg"
        :disabled="busy"
        :aria-label="$t('projects.source.remove')"
        :title="$t('projects.source.remove')"
        data-testid="btn-project-source-remove"
        @click="emit('remove', source.id)"
      >
        <TrashIcon class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  </li>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import {
  CheckCircleIcon,
  DocumentTextIcon,
  LinkIcon,
  PaperClipIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import type { ProjectSourceItem } from '@/services/api/projectsApi'
import { formatFileSize } from '@/utils/messageFileDisplay'

const props = defineProps<{
  source: ProjectSourceItem
  busy?: boolean
}>()

const emit = defineEmits<{
  retry: [fileId: number]
  remove: [fileId: number]
}>()

const kindIcon = computed(() => {
  if (props.source.kind === 'link') return LinkIcon
  if (props.source.kind === 'text') return DocumentTextIcon
  return PaperClipIcon
})

/** Only a web address is made clickable; anything else stays plain text. */
const linkHref = computed(() => {
  const url = props.source.sourceUrl
  return url && /^https?:\/\//i.test(url) ? url : null
})

const canRetry = computed(() => props.source.state === 'failed' && props.source.kind === 'link')
const sizeLabel = computed(() => formatFileSize(props.source.size))
</script>
