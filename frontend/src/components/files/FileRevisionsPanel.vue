<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    data-testid="file-revisions-panel"
    @click.self="emit('close')"
  >
    <div
      class="surface-card w-full max-w-md rounded-xl border border-light-border/30 dark:border-dark-border/20 p-4 shadow-xl"
    >
      <div class="flex items-start justify-between gap-3 mb-3">
        <h2 class="text-sm font-semibold txt-primary">{{ $t('files.revisionsTitle') }}</h2>
        <button
          type="button"
          class="icon-ghost"
          :aria-label="$t('common.close')"
          @click="emit('close')"
        >
          <Icon icon="mdi:close" class="w-4 h-4" />
        </button>
      </div>
      <p v-if="loading" class="text-sm txt-secondary">{{ $t('files.status_extracting') }}</p>
      <p v-else-if="revisions.length === 0" class="text-sm txt-secondary">
        {{ $t('files.noRevisions') }}
      </p>
      <ul v-else class="space-y-2 max-h-72 overflow-y-auto">
        <li
          v-for="rev in revisions"
          :key="rev.version"
          class="flex items-center justify-between gap-2 rounded-lg surface-chip px-3 py-2"
        >
          <div class="min-w-0">
            <div class="text-xs font-medium txt-primary">
              {{ $t('files.restoreVersion', { version: rev.version }) }}
            </div>
            <div class="text-xs txt-tertiary truncate">{{ rev.summary }}</div>
          </div>
          <button
            type="button"
            class="btn-secondary text-xs shrink-0"
            :data-testid="`file-revision-restore-${rev.version}`"
            @click="onRestore(rev.version)"
          >
            {{ $t('files.restoreVersion', { version: rev.version }) }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import * as filesService from '@/services/filesService'
import type { FileRevision } from '@/services/filesService'

const props = defineProps<{ fileId: number }>()
const emit = defineEmits<{ close: []; restored: [] }>()

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()
const revisions = ref<FileRevision[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const data = await filesService.listFileRevisions(props.fileId)
    revisions.value = data.revisions
  } catch {
    revisions.value = []
  } finally {
    loading.value = false
  }
})

const onRestore = async (version: number) => {
  const ok = await confirm({
    title: t('files.revisionsTitle'),
    message: t('files.restoreConfirm', { version }),
  })
  if (!ok) return
  try {
    await filesService.restoreFileRevision(props.fileId, version)
    success(t('files.restored'))
    emit('restored')
    emit('close')
  } catch {
    error(t('files.restoreFailed'))
  }
}
</script>
