<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Icon } from '@iconify/vue'
import JobsTray from '@/components/jobs/JobsTray.vue'
import { useMediaJobsStore } from '@/stores/mediaJobs'

/**
 * Floating launcher for the global Jobs tray (Release 4.0, Sprint D). It only
 * appears while there are active background renders — a non-intrusive "N
 * running" pill the user can click to open the tray and keep working. Mounted
 * once in the app shell; it is self-contained and never touches the sidebar.
 */
const mediaJobsStore = useMediaJobsStore()
const isOpen = ref(false)

const activeCount = computed(() => mediaJobsStore.activeCount)
const visible = computed(() => activeCount.value > 0 || isOpen.value)

function toggle(): void {
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    void mediaJobsStore.loadActive()
  }
}

// Auto-close the tray once everything has finished.
watch(activeCount, (count) => {
  if (count === 0) isOpen.value = false
})
</script>

<template>
  <Teleport to="body">
    <button
      v-if="visible"
      type="button"
      class="surface-card fixed bottom-4 left-4 z-[999] inline-flex items-center gap-2 rounded-full border border-brand px-3 py-2 text-sm font-medium text-brand shadow-md hover:bg-black/5 dark:hover:bg-white/5"
      :aria-label="$t('jobs.tray.openLabel', { count: activeCount })"
      data-testid="jobs-tray-launcher"
      @click="toggle"
    >
      <Icon icon="mdi:loading" class="w-4 h-4 animate-spin" aria-hidden="true" />
      <span data-testid="jobs-tray-badge">{{ activeCount }}</span>
    </button>

    <JobsTray :open="isOpen" @close="isOpen = false" />
  </Teleport>
</template>
