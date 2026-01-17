<template>
  <Teleport to="body">
    <TransitionGroup name="toast-slide" tag="div" class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="memory in memories"
        :key="memory.toastId"
        class="surface-card rounded-xl shadow-2xl border border-light-border/20 dark:border-dark-border/20 p-4 max-w-md animate-scale-in"
        data-testid="memory-toast"
      >
        <!-- Header -->
        <div class="flex items-start justify-between gap-3 mb-3">
          <div class="flex items-center gap-2">
            <div
              class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-brand/10"
            >
              <Icon icon="mdi:brain" class="w-5 h-5 text-brand" />
            </div>
            <div>
              <h3 class="text-sm font-semibold txt-primary">
                {{ $t('memories.toast.title') }}
              </h3>
              <p class="text-xs txt-secondary">{{ memory.category }}</p>
            </div>
          </div>
          <button
            class="flex-shrink-0 w-6 h-6 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
            @click="dismiss(memory.toastId)"
          >
            <Icon icon="mdi:close" class="w-4 h-4 txt-secondary" />
          </button>
        </div>

        <!-- Memory Content -->
        <div class="space-y-2 mb-4">
          <div class="text-xs txt-secondary font-medium">{{ memory.key }}</div>
          <div class="text-sm txt-primary bg-black/5 dark:bg-white/5 rounded-lg p-3">
            {{ memory.value }}
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-2">
          <button
            class="flex-1 px-3 py-2 rounded-lg text-sm font-medium border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all"
            @click="discard(memory)"
          >
            {{ $t('memories.toast.discard') }}
          </button>
          <button
            class="flex-1 px-3 py-2 rounded-lg text-sm font-medium btn-primary"
            @click="edit(memory)"
          >
            {{ $t('memories.toast.edit') }}
          </button>
        </div>
      </div>
    </TransitionGroup>
  </Teleport>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface MemoryWithToast extends UserMemory {
  toastId: number
}

interface Props {
  memories: MemoryWithToast[]
}

interface Emits {
  (e: 'dismiss', toastId: number): void
  (e: 'discard', memory: MemoryWithToast): void
  (e: 'edit', memory: MemoryWithToast): void
}

defineProps<Props>()
const emit = defineEmits<Emits>()

const dismiss = (toastId: number) => {
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
