<template>
  <Teleport to="body">
    <TransitionGroup name="toast-slide" tag="div" class="fixed bottom-4 right-4 z-50 space-y-2">
      <div
        v-for="memory in memories"
        :key="memory.toastId"
        class="surface-card rounded-lg shadow-lg border border-light-border/20 dark:border-dark-border/20 p-3 max-w-xs"
        data-testid="memory-toast"
      >
        <!-- Compact Header -->
        <div class="flex items-center justify-between gap-2 mb-2">
          <div class="flex items-center gap-2 min-w-0 flex-1">
            <div
              class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center bg-brand/10"
            >
              <Icon icon="mdi:brain" class="w-3.5 h-3.5 text-brand" />
            </div>
            <div class="min-w-0 flex-1">
              <h3 class="text-xs font-semibold txt-primary truncate">
                {{ $t('memories.toast.titleCompact') }}
              </h3>
            </div>
          </div>
          <button
            class="relative flex-shrink-0 w-6 h-6 rounded-full hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors group"
            @click="dismiss(memory.toastId)"
          >
            <!-- Animated Circle Progress -->
            <svg class="absolute inset-0 w-6 h-6 -rotate-90" viewBox="0 0 24 24">
              <!-- Background circle -->
              <circle
                cx="12"
                cy="12"
                :r="CIRCLE_RADIUS"
                fill="none"
                stroke="currentColor"
                class="txt-secondary opacity-20"
                stroke-width="2"
              />
              <!-- Progress circle -->
              <circle
                cx="12"
                cy="12"
                :r="CIRCLE_RADIUS"
                fill="none"
                stroke="currentColor"
                class="text-brand transition-all duration-150 ease-linear"
                stroke-width="2"
                :stroke-dasharray="CIRCLE_CIRCUMFERENCE"
                :stroke-dashoffset="getStrokeDashoffset(memory.toastId)"
                stroke-linecap="round"
              />
            </svg>
            <!-- X Icon -->
            <Icon
              icon="mdi:close"
              class="relative w-3 h-3 txt-secondary group-hover:txt-primary transition-colors"
            />
          </button>
        </div>

        <!-- Compact Content -->
        <div class="space-y-1.5 mb-2">
          <div class="flex items-center gap-1.5">
            <span class="pill text-[10px] px-1.5 py-0.5">{{ memory.category }}</span>
            <span class="text-xs txt-secondary font-medium truncate">{{ memory.key }}</span>
          </div>
          <div class="text-xs txt-primary line-clamp-2">
            {{ memory.value }}
          </div>
        </div>

        <!-- Compact Actions -->
        <div class="flex items-center gap-1.5">
          <button
            class="flex-1 px-2 py-1.5 rounded text-xs font-medium border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all"
            @click="discard(memory)"
          >
            {{ $t('memories.toast.discardCompact') }}
          </button>
          <button
            class="flex-1 px-2 py-1.5 rounded text-xs font-medium btn-primary"
            @click="edit(memory)"
          >
            {{ $t('memories.toast.editCompact') }}
          </button>
        </div>
      </div>
    </TransitionGroup>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface MemoryWithToast extends UserMemory {
  toastId: number
}

interface Props {
  memories: MemoryWithToast[]
  autoHideDuration?: number // in milliseconds
}

interface Emits {
  (e: 'dismiss', toastId: number): void
  (e: 'discard', memory: MemoryWithToast): void
  (e: 'edit', memory: MemoryWithToast): void
}

const props = withDefaults(defineProps<Props>(), {
  autoHideDuration: 8000, // 8 seconds default
})
const emit = defineEmits<Emits>()

// Track progress for each toast
const progressMap = ref<Map<number, number>>(new Map())
const timersMap = ref<Map<number, number>>(new Map())

const CIRCLE_RADIUS = 10
const CIRCLE_CIRCUMFERENCE = 2 * Math.PI * CIRCLE_RADIUS

const updateProgress = (toastId: number) => {
  const startTime = Date.now()
  const duration = props.autoHideDuration

  const animate = () => {
    const elapsed = Date.now() - startTime
    const progress = Math.min(elapsed / duration, 1)
    progressMap.value.set(toastId, progress)

    if (progress < 1) {
      const timerId = requestAnimationFrame(animate)
      timersMap.value.set(toastId, timerId)
    } else {
      // Auto-dismiss when progress reaches 100%
      dismiss(toastId)
    }
  }

  animate()
}

const getStrokeDashoffset = (toastId: number): number => {
  const progress = progressMap.value.get(toastId) || 0
  return CIRCLE_CIRCUMFERENCE * (1 - progress)
}

// Watch for new memories and start their timers (including initial load)
watch(
  () => props.memories,
  (newMemories) => {
    newMemories.forEach((memory) => {
      if (!progressMap.value.has(memory.toastId)) {
        progressMap.value.set(memory.toastId, 0)
        updateProgress(memory.toastId)
      }
    })
  },
  { immediate: true, deep: true }
)

// Cleanup timers
onUnmounted(() => {
  timersMap.value.forEach((timerId) => cancelAnimationFrame(timerId))
  timersMap.value.clear()
})

const dismiss = (toastId: number) => {
  // Cancel animation timer
  const timerId = timersMap.value.get(toastId)
  if (timerId) {
    cancelAnimationFrame(timerId)
    timersMap.value.delete(toastId)
  }
  progressMap.value.delete(toastId)
  emit('dismiss', toastId)
}

const discard = (memory: MemoryWithToast) => {
  emit('discard', memory)
}

const edit = (memory: MemoryWithToast) => {
  emit('edit', memory)
}
</script>

<style scoped>
.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.3s ease;
}

.toast-slide-enter-from {
  opacity: 0;
  transform: translateX(100%);
}

.toast-slide-leave-to {
  opacity: 0;
  transform: translateX(100%);
}

.toast-slide-move {
  transition: transform 0.3s ease;
}
</style>
