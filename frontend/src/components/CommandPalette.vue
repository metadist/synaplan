<template>
  <!-- Mobile Backdrop -->
  <Transition
    enter-active-class="transition-opacity duration-200"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-150"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="visible"
      class="fixed inset-0 bg-black/30 z-[59] md:hidden"
      data-testid="backdrop-command-palette"
      @click="emit('close')"
    />
  </Transition>

  <!-- Command Palette -->
  <Transition
    enter-active-class="transition-all duration-200 ease-out"
    enter-from-class="md:opacity-0 md:scale-95 translate-y-full md:translate-y-0"
    enter-to-class="md:opacity-100 md:scale-100 translate-y-0"
    leave-active-class="transition-all duration-150 ease-in"
    leave-from-class="md:opacity-100 md:scale-100 translate-y-0"
    leave-to-class="md:opacity-0 md:scale-95 translate-y-full md:translate-y-0"
  >
    <div
      v-if="visible"
      class="fixed bottom-0 left-0 right-0 md:bottom-32 md:left-1/2 md:-translate-x-1/2 md:w-[720px] md:max-w-[92vw] surface-card border-t md:border border-light-border/30 dark:border-dark-border/30 md:rounded-xl z-[60] h-[85vh] md:h-auto md:max-h-[70vh] flex flex-col"
      role="menu"
      data-testid="comp-command-palette"
      @click.stop
    >
      <!-- Header -->
      <div
        class="flex-shrink-0 px-4 py-3 border-b border-light-border/20 dark:border-dark-border/20 bg-black/5 dark:bg-white/5"
      >
        <p class="text-xs txt-secondary flex flex-wrap gap-x-3 gap-y-1.5">
          <span class="flex items-center gap-1.5">
            <kbd class="px-1.5 py-0.5 text-xs font-mono bg-black/10 dark:bg-white/10 rounded"
              >↑</kbd
            >
            <kbd class="px-1.5 py-0.5 text-xs font-mono bg-black/10 dark:bg-white/10 rounded"
              >↓</kbd
            >
            <span class="ml-0.5">{{ $t('commands.navigate') }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <kbd class="px-1.5 py-0.5 text-xs font-mono bg-black/10 dark:bg-white/10 rounded"
              >Enter</kbd
            >
            <span class="ml-0.5">{{ $t('commands.select') }}</span>
          </span>
          <span class="flex items-center gap-1.5">
            <kbd class="px-1.5 py-0.5 text-xs font-mono bg-black/10 dark:bg-white/10 rounded"
              >Esc</kbd
            >
            <span class="ml-0.5">{{ $t('commands.close') }}</span>
          </span>
        </p>
      </div>

      <!-- Content -->
      <div
        v-if="filteredCommands.length === 0"
        class="flex-1 flex items-center justify-center px-4 py-6 text-sm txt-secondary"
      >
        {{ $t('commands.noResults') }}
      </div>
      <div v-else class="flex-1 overflow-y-auto scroll-thin py-1">
        <button
          v-for="(cmd, index) in filteredCommands"
          :key="cmd.name"
          :ref="(el) => setItemRef(el, index)"
          :class="[
            'dropdown-item w-full text-left',
            selectedIndex === index && 'dropdown-item--active',
          ]"
          role="menuitem"
          type="button"
          @click="selectCommand(cmd)"
          @mouseenter="selectedIndex = index"
        >
          <div class="flex items-start gap-3 min-w-0">
            <Icon :icon="cmd.icon" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
            <div class="flex-1 min-w-0">
              <div class="font-mono text-sm font-semibold txt-primary">{{ cmd.usage }}</div>
              <div class="text-xs txt-secondary truncate">{{ cmd.description }}</div>
            </div>
          </div>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { Icon } from '@iconify/vue'
import { useCommandsStore, type Command } from '@/stores/commands'

const commandsStore = useCommandsStore()

interface Props {
  visible: boolean
  query: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  select: [command: Command]
  close: []
}>()

const selectedIndex = ref(0)
const itemRefs = ref<Array<HTMLElement | null>>([])

const setItemRef = (el: any, index: number) => {
  if (el) {
    itemRefs.value[index] = el as HTMLElement
  }
}

const filteredCommands = computed(() => {
  const allCommands = commandsStore.commands

  const query = props.query.toLowerCase().replace(/^\//, '').trim()

  if (!query) {
    return allCommands
  }

  return allCommands.filter(
    (cmd) =>
      cmd.name.toLowerCase().includes(query) ||
      cmd.description.toLowerCase().includes(query) ||
      cmd.usage.toLowerCase().includes(query)
  )
})

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      selectedIndex.value = 0
      itemRefs.value = []
    }
  }
)

watch(filteredCommands, () => {
  selectedIndex.value = 0
})

const selectCommand = (cmd: Command) => {
  emit('select', cmd)
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (!props.visible || filteredCommands.value.length === 0) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    e.stopPropagation()
    selectedIndex.value = Math.min(selectedIndex.value + 1, filteredCommands.value.length - 1)
    scrollToSelected()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    e.stopPropagation()
    selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    scrollToSelected()
  } else if (e.key === 'Enter' && filteredCommands.value.length > 0) {
    e.preventDefault()
    e.stopPropagation()
    if (filteredCommands.value[selectedIndex.value]) {
      selectCommand(filteredCommands.value[selectedIndex.value])
    }
  } else if (e.key === 'Escape') {
    e.preventDefault()
    e.stopPropagation()
    emit('close')
  } else if (e.key === 'Tab') {
    e.preventDefault()
    e.stopPropagation()
    if (filteredCommands.value[selectedIndex.value]) {
      selectCommand(filteredCommands.value[selectedIndex.value])
    }
  }
}

const scrollToSelected = () => {
  nextTick(() => {
    const item = itemRefs.value[selectedIndex.value]
    if (item) {
      item.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }
  })
}

defineExpose({ handleKeyDown })
</script>
