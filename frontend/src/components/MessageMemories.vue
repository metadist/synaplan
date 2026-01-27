<template>
  <div v-if="memories.length > 0" class="mt-3">
    <button
      class="flex items-center gap-2 px-3 py-2 rounded-lg surface-chip hover:bg-black/5 dark:hover:bg-white/5 transition-all w-full text-left"
      @click="toggleExpand"
    >
      <Icon icon="mdi:brain" class="w-4 h-4 flex-shrink-0 txt-brand" />
      <span class="text-xs font-medium txt-secondary flex-1">
        {{ $t('memories.usedInResponse', { count: memories.length }) }}
      </span>
      <Icon
        :icon="isExpanded ? 'mdi:chevron-up' : 'mdi:chevron-down'"
        class="w-4 h-4 txt-secondary transition-transform flex-shrink-0"
      />
    </button>

    <!-- Expandable Content -->
    <Transition name="expand">
      <div v-if="isExpanded" class="mt-2 space-y-2">
        <div
          v-for="(memory, index) in memories"
          :key="memory.id"
          :ref="(el) => (memoryRefs[index] = el as HTMLElement)"
          :class="[
            'surface-chip rounded-lg p-2.5 transition-all cursor-pointer',
            highlightedMemory === index
              ? 'ring-2 ring-brand bg-brand-alpha-light'
              : 'hover:bg-black/5 dark:hover:bg-white/5',
          ]"
          @click="navigateToMemory(memory)"
        >
          <div class="flex items-start gap-2">
            <div
              class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-1.5"
              :class="getCategoryColorClass(memory.category)"
            ></div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-1.5 mb-1">
                <span class="pill text-[10px] px-1.5 py-0.5">{{ memory.category }}</span>
                <span class="text-xs font-medium txt-secondary truncate">{{ memory.key }}</span>
              </div>
              <div class="text-xs txt-primary">{{ memory.value }}</div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  memories: UserMemory[]
}

const props = defineProps<Props>()

const router = useRouter()
const isExpanded = ref(false)
const highlightedMemory = ref<number | null>(null)
const memoryRefs = ref<HTMLElement[]>([])

const getCategoryColorClass = (category: string) => {
  const colors: Record<string, string> = {
    preferences: 'bg-blue-500',
    personal: 'bg-green-500',
    work: 'bg-purple-500',
    projects: 'bg-orange-500',
  }
  return colors[category] || 'bg-gray-500'
}

const toggleExpand = () => {
  isExpanded.value = !isExpanded.value
}

const navigateToMemory = (memory: UserMemory) => {
  router.push({
    path: '/memories',
    query: { highlight: memory.id.toString() },
  })
}

// Listen for memory reference clicks from the message text
const handleMemoryRefClick = (event: CustomEvent) => {
  const { memoryId } = event.detail as { memoryId: number }
  const memoryIndex = props.memories.findIndex((m) => m.id === memoryId)
  if (memoryIndex >= 0) {
    highlightedMemory.value = memoryIndex

    // Auto-expand if collapsed
    if (!isExpanded.value) {
      isExpanded.value = true
    }

    // Scroll to the memory after expansion
    setTimeout(() => {
      const memoryEl = memoryRefs.value[memoryIndex]
      if (memoryEl) {
        memoryEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      }
    }, 300)

    // Remove highlight after 2 seconds
    setTimeout(() => {
      highlightedMemory.value = null
    }, 2000)
  }
}

onMounted(() => {
  window.addEventListener('memory-ref-clicked' as any, handleMemoryRefClick as any)
})

onUnmounted(() => {
  window.removeEventListener('memory-ref-clicked' as any, handleMemoryRefClick as any)
})
</script>

<style scoped>
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 400px;
  opacity: 1;
}
</style>
