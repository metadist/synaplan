<template>
  <div class="relative" data-testid="comp-tools-dropdown">
    <button
      @click="toggleOpen"
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="$t('chatInput.tools.label')"
      @keydown.escape="closeDropdown"
      data-testid="btn-tools-toggle"
    >
      <WrenchScrewdriverIcon class="w-4 h-4 md:w-5 md:h-5" />
      <span class="text-xs md:text-sm font-medium hidden sm:inline">{{ $t('chatInput.tools.label') }}</span>
      <ChevronUpIcon class="w-4 h-4" />
    </button>
    <div
      v-if="isOpen"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-80 max-h-[60vh] overflow-y-auto scroll-thin"
      @keydown.escape="closeDropdown"
      data-testid="dropdown-tools-panel"
    >
      <!-- Web Search Tool -->
      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          isToolActive('web-search') && 'dropdown-item--active',
          isToolDisabled('web-search') && 'opacity-60'
        ]"
        @click="selectTool('web-search')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
        data-testid="btn-tool-web-search"
      >
        <Icon icon="mdi:web" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t('chatInput.tools.webSearch') }}</span>
            <span 
              v-if="isToolDisabled('web-search')" 
              class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200"
            >
              Setup Required
            </span>
            <span 
              v-else-if="!isLoadingFeatures" 
              class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200"
            >
              Ready
            </span>
          </div>
          <div class="text-xs txt-secondary">
            {{ isToolDisabled('web-search') ? getToolMessage('web-search') : $t('chatInput.tools.webSearchDesc') }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon 
            v-if="isToolActive('web-search')" 
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
      </button>

      <!-- Image Generation Tool -->
      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          isToolActive('image-gen') && 'dropdown-item--active',
          isToolDisabled('image-gen') && 'opacity-60'
        ]"
        @click="selectTool('image-gen')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
        data-testid="btn-tool-image-gen"
      >
        <Icon icon="mdi:image" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t('chatInput.tools.imageGen') }}</span>
            <span 
              v-if="isToolDisabled('image-gen')" 
              class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200"
            >
              Setup Required
            </span>
            <span 
              v-else-if="!isLoadingFeatures" 
              class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200"
            >
              Ready
            </span>
          </div>
          <div class="text-xs txt-secondary">
            {{ isToolDisabled('image-gen') ? getToolMessage('image-gen') : $t('chatInput.tools.imageGenDesc') }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon 
            v-if="isToolActive('image-gen')" 
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
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
        data-testid="btn-tool-code-interpreter"
      >
        <Icon icon="mdi:code-braces" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">{{ $t('chatInput.tools.codeInterpreter') }}</div>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.codeInterpreterDesc') }}</div>
        </div>
        <Transition name="check-fade">
          <CheckIcon 
            v-if="isToolActive('code-interpreter')" 
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
      </button>

      <div class="border-t border-light-border dark:border-dark-border my-1"></div>

      <!-- Slash Commands -->
      <button
        v-for="cmd in availableCommands"
        :key="cmd.name"
        ref="itemRefs"
        class="dropdown-item flex items-center justify-between"
        @click="selectCommand(cmd)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
        type="button"
      >
        <code class="font-mono text-sm txt-primary">{{ cmd.usage }}</code>
        <span class="text-xs txt-secondary text-right ml-auto">{{ cmd.description }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { WrenchScrewdriverIcon, ChevronUpIcon, CheckIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { commandsData as commands, type Command } from '@/stores/commands'
import { getFeaturesStatus, type Feature } from '@/services/featuresService'
import { useRouter } from 'vue-router'

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

const router = useRouter()
const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])
const featuresStatus = ref<Record<string, Feature>>({})
const isLoadingFeatures = ref(true)

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

const isToolDisabled = (toolId: string): boolean => {
  const feature = featuresStatus.value[toolId]
  return feature ? !feature.enabled : false
}

const getToolMessage = (toolId: string): string => {
  const feature = featuresStatus.value[toolId]
  return feature?.message || ''
}

const loadFeaturesStatus = async () => {
  try {
    isLoadingFeatures.value = true
    const status = await getFeaturesStatus()
    featuresStatus.value = status.features
  } catch (error) {
    console.error('Failed to load features status:', error)
  } finally {
    isLoadingFeatures.value = false
  }
}

const toggleOpen = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value && Object.keys(featuresStatus.value).length === 0) {
    loadFeaturesStatus()
  }
}

const closeDropdown = () => {
  isOpen.value = false
}

const selectTool = (toolId: string) => {
  const feature = featuresStatus.value[toolId]
  
  // If feature is disabled, navigate to setup instructions instead
  if (feature && !feature.enabled && feature.setup_required) {
    router.push({ 
      path: '/settings', 
      query: { tab: 'features', feature: toolId } 
    })
    closeDropdown()
    return
  }
  
  // Emit select event (toggle tool on/off)
  emit('select', toolId)
  
  // Don't close dropdown - allow multi-select
  // closeDropdown()
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

<style scoped>
.check-fade-enter-active {
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.check-fade-leave-active {
  transition: all 0.2s ease-in;
}

.check-fade-enter-from {
  opacity: 0;
  transform: scale(0.5) rotate(-90deg);
}

.check-fade-leave-to {
  opacity: 0;
  transform: scale(0.8);
}
</style>
