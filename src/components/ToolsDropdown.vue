<template>
  <div class="relative">
    <button
      @click="toggleOpen"
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="$t('chatInput.tools.label')"
      @keydown.escape="closeDropdown"
    >
      <WrenchScrewdriverIcon class="w-4 h-4 md:w-5 md:h-5" />
      <span class="text-xs md:text-sm font-medium hidden sm:inline">{{ $t('chatInput.tools.label') }}</span>
      <ChevronUpIcon class="w-4 h-4" />
    </button>
    <div
      v-if="isOpen"
      class="dropdown-up left-0 sm:left-auto max-h-[60vh] overflow-y-auto scroll-thin"
      @keydown.escape="closeDropdown"
    >
      <!-- Web Search Tool -->
      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          isToolActive('web-search') && 'dropdown-item--active'
        ]"
        @click="selectTool('web-search')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
      >
        <Icon icon="mdi:web" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">{{ $t('chatInput.tools.webSearch') }}</div>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.webSearchDesc') }}</div>
        </div>
      </button>

      <!-- Image Generation Tool -->
      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          isToolActive('image-gen') && 'dropdown-item--active'
        ]"
        @click="selectTool('image-gen')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
      >
        <Icon icon="mdi:image" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">{{ $t('chatInput.tools.imageGen') }}</div>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.imageGenDesc') }}</div>
        </div>
      </button>

      <!-- Code Interpreter Tool -->
      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          isToolActive('code-interpreter') && 'dropdown-item--active'
        ]"
        @click="selectTool('code-interpreter')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
      >
        <Icon icon="mdi:code-braces" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">{{ $t('chatInput.tools.codeInterpreter') }}</div>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.codeInterpreterDesc') }}</div>
        </div>
      </button>

      <div class="border-t border-light-border dark:border-dark-border my-1"></div>

      <!-- Slash Commands -->
      <button
        v-for="cmd in availableCommands"
        :key="cmd.name"
        ref="itemRefs"
        class="dropdown-item"
        @click="selectCommand(cmd)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
      >
        <code class="font-mono text-sm txt-primary">{{ cmd.usage }}</code>
        <span class="text-xs txt-secondary ml-2">{{ cmd.description }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { WrenchScrewdriverIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { commandsData as commands, type Command } from '@/stores/commands'

interface Tool {
  id: string
  name: string
  icon: string
}

interface Props {
  activeTools?: Tool[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
  select: [toolId: string]
  remove: [toolId: string]
}>()

const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])

// Filter commands to show only the most useful ones for the tools menu
const availableCommands = computed(() => {
  return commands.filter(cmd => 
    !cmd.name.startsWith('test') && // Exclude test commands
    cmd.name !== 'list' // Exclude the list command itself
  )
})

const isToolActive = (toolId: string): boolean => {
  return props.activeTools?.some(t => t.id === toolId) ?? false
}

const toggleOpen = () => {
  isOpen.value = !isOpen.value
}

const closeDropdown = () => {
  isOpen.value = false
}

const selectTool = (toolId: string) => {
  emit('select', toolId)
  closeDropdown()
}

const selectCommand = (cmd: Command) => {
  // For now, just close the dropdown
  // In the future, this could insert the command or trigger an action
  closeDropdown()
}

const focusNext = () => {
  const currentIndex = itemRefs.value.findIndex(el => el === document.activeElement)
  const nextIndex = (currentIndex + 1) % itemRefs.value.length
  itemRefs.value[nextIndex]?.focus()
}

const focusPrevious = () => {
  const currentIndex = itemRefs.value.findIndex(el => el === document.activeElement)
  const prevIndex = currentIndex <= 0 ? itemRefs.value.length - 1 : currentIndex - 1
  itemRefs.value[prevIndex]?.focus()
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (isOpen.value && !target.closest('.relative')) {
    closeDropdown()
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))
</script>
