<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import JobRow from '@/components/jobs/JobRow.vue'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useChatsStore } from '@/stores/chats'

defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const mediaJobsStore = useMediaJobsStore()
const chatsStore = useChatsStore()

const activeJobs = computed(() => mediaJobsStore.activeJobs)

function chatTitleFor(chatId: number | null | undefined): string | undefined {
  if (chatId == null) return undefined
  return chatsStore.chats.find((c) => c.id === chatId)?.title
}

function handleOpen(chatId: number): void {
  mediaJobsStore.openChat(chatId)
  emit('close')
}
</script>

<template>
  <Transition
    enter-active-class="transition-transform duration-200 ease-out"
    enter-from-class="translate-x-full"
    leave-active-class="transition-transform duration-200 ease-in"
    leave-to-class="translate-x-full"
  >
    <aside
      v-if="open"
      class="surface-card fixed right-3 bottom-3 top-3 z-[1000] flex w-[360px] max-w-[calc(100vw-1.5rem)] flex-col rounded-xl border border-[var(--border-light)] shadow-lg"
      role="dialog"
      :aria-label="$t('jobs.tray.title')"
      data-testid="jobs-tray"
    >
      <header
        class="flex items-center justify-between border-b border-[var(--border-light)] px-4 py-3"
      >
        <p class="text-sm font-semibold txt-primary">{{ $t('jobs.tray.title') }}</p>
        <button
          type="button"
          class="rounded-md p-1 txt-muted hover:bg-black/5 dark:hover:bg-white/5"
          :aria-label="$t('jobs.tray.close')"
          data-testid="jobs-tray-close"
          @click="emit('close')"
        >
          <Icon icon="mdi:close" class="w-5 h-5" aria-hidden="true" />
        </button>
      </header>

      <div class="flex-1 overflow-y-auto p-3">
        <div
          v-if="activeJobs.length === 0"
          class="px-2 py-8 text-center text-sm txt-muted"
          data-testid="jobs-tray-empty"
        >
          {{ $t('jobs.tray.empty') }}
        </div>
        <div v-else class="flex flex-col gap-2">
          <JobRow
            v-for="job in activeJobs"
            :key="job.jobId"
            :job="job"
            :chat-title="chatTitleFor(job.chatId)"
            @open="handleOpen"
            @cancel="mediaJobsStore.cancel($event)"
          />
        </div>
      </div>
    </aside>
  </Transition>
</template>
