<template>
  <div v-if="memories.length > 0" class="mb-4">
    <div
      class="surface-card rounded-xl border border-light-border/20 dark:border-dark-border/20 overflow-hidden"
    >
      <!-- Header (always visible) -->
      <button
        class="w-full flex items-center justify-between p-4 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        @click="isExpanded = !isExpanded"
      >
        <div class="flex items-center gap-3">
          <div
            class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-brand/10"
          >
            <Icon icon="mdi:brain" class="w-5 h-5 text-brand" />
          </div>
          <div class="text-left">
            <h3 class="text-sm font-semibold txt-primary">
              {{ $t('memories.context.title') }}
            </h3>
            <p class="text-xs txt-secondary">
              {{ $t('memories.context.count', { count: memories.length }) }}
            </p>
          </div>
        </div>
        <Icon
          :icon="isExpanded ? 'mdi:chevron-up' : 'mdi:chevron-down'"
          class="w-5 h-5 txt-secondary transition-transform"
        />
      </button>

      <!-- Expandable Content -->
      <Transition name="expand">
        <div v-if="isExpanded" class="border-t border-light-border/10 dark:border-dark-border/10">
          <div class="p-4 space-y-3 max-h-64 overflow-y-auto scroll-thin">
            <div
              v-for="memory in memories"
              :key="memory.id"
              class="surface-chip rounded-lg p-3 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            >
              <div class="flex items-start justify-between gap-2 mb-1">
                <div class="text-xs font-medium txt-secondary">{{ memory.key }}</div>
                <div class="pill text-xs">{{ memory.category }}</div>
              </div>
              <div class="text-sm txt-primary">{{ memory.value }}</div>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  memories: UserMemory[]
}

defineProps<Props>()

const isExpanded = ref(false)
</script>

<style scoped>
.expand-enter-active,
.expand-leave-active {
  transition: all 0.3s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 500px;
  opacity: 1;
}
</style>
