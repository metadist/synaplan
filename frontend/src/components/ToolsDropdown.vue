<template>
  <div ref="dropdownRef" class="relative" data-testid="comp-tools-dropdown">
    <button
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="$t('chatInput.tools.label')"
      data-testid="btn-tools-toggle"
      @click="toggleOpen"
      @keydown.escape="closeDropdown"
    >
      <WrenchScrewdriverIcon class="w-4 h-4 md:w-5 md:h-5" />
      <span class="text-xs md:text-sm font-medium">{{ $t('chatInput.tools.label') }}</span>
      <!-- Q8: active toggles (Thinking / Voice reply) surface on the pill as a dot -->
      <span
        v-if="activeToggleCount > 0"
        class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] flex-shrink-0"
        data-testid="badge-tools-active"
        aria-hidden="true"
      />
      <ChevronUpIcon class="w-4 h-4" />
    </button>
    <div
      v-if="isOpen"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-80 max-h-[60vh] overflow-y-auto scroll-thin"
      data-testid="dropdown-tools-panel"
      @keydown.escape="closeDropdown"
    >
      <!-- Command tools: insert a /command into the input -->
      <button
        v-for="tool in commandTools"
        ref="itemRefs"
        :key="tool.id"
        :class="[
          'dropdown-item',
          isToolActive(tool.command) && 'dropdown-item--active',
          isToolDisabled(tool.id) && 'opacity-60',
        ]"
        type="button"
        :data-testid="`btn-tool-${tool.id}`"
        @click="selectToolCommand(tool.id, tool.command)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon :icon="tool.icon" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t(tool.labelKey) }}</span>
            <span
              v-if="isToolDisabled(tool.id)"
              class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200"
            >
              {{ $t('chatInput.tools.setupRequired') }}
            </span>
            <span
              v-else-if="!isLoadingFeatures"
              class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200"
            >
              {{ $t('chatInput.tools.ready') }}
            </span>
          </div>
          <div class="text-xs txt-secondary">
            {{ isToolDisabled(tool.id) ? getToolMessage(tool.id) : $t(tool.descKey) }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon
            v-if="isToolActive(tool.command)"
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
      </button>

      <!-- §4.7 #2: behaviour toggles live here, not as standalone pills -->
      <div class="border-t border-light-border/20 dark:border-dark-border/20 my-1" />

      <button
        ref="itemRefs"
        :class="[
          'dropdown-item',
          thinkingEnabled && 'dropdown-item--active',
          !supportsReasoning && 'opacity-60',
        ]"
        type="button"
        data-testid="btn-tool-thinking"
        @click="emit('toggleThinking')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon icon="mdi:lightbulb-on-outline" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium">{{ $t('chatInput.thinking') }}</span>
          <div class="text-xs txt-secondary">
            {{
              supportsReasoning
                ? $t('chatInput.tools.thinkingDesc')
                : $t('chatInput.tools.thinkingUnsupported')
            }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon v-if="thinkingEnabled" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
        </Transition>
      </button>

      <button
        ref="itemRefs"
        :class="['dropdown-item', voiceReply && 'dropdown-item--active']"
        type="button"
        data-testid="btn-tool-voice-reply"
        @click="emit('toggleVoiceReply')"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon icon="mdi:volume-high" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium">{{ $t('chatInput.voiceReply') }}</span>
          <div class="text-xs txt-secondary">{{ $t('chatInput.voiceReplyTooltip') }}</div>
        </div>
        <Transition name="check-fade">
          <CheckIcon v-if="voiceReply" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
        </Transition>
      </button>

      <!-- Enhance lives here on mobile only — the in-shell sparkles button is
           desktop-only because it crowds the narrow input (§4.7 follow-up).
           v-if (not a hidden class): .dropdown-item's unlayered display rule
           would beat md:hidden, and hidden rows must not join focus cycling. -->
      <button
        v-if="isMobileViewport"
        ref="itemRefs"
        :class="[
          'dropdown-item',
          enhanceEnabled && 'dropdown-item--active',
          !enhanceAvailable && 'opacity-60',
        ]"
        type="button"
        :disabled="enhanceLoading"
        data-testid="btn-tool-enhance"
        @click="handleEnhance"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon v-if="enhanceLoading" icon="mdi:loading" class="w-5 h-5 flex-shrink-0 animate-spin" />
        <Icon v-else icon="mdi:creation" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium">{{ $t('chatInput.enhance') }}</span>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.enhanceDesc') }}</div>
        </div>
        <Transition name="check-fade">
          <CheckIcon v-if="enhanceEnabled" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
        </Transition>
      </button>

      <!-- Q3: the pre-configured Summarizer stays reachable from chat; this is
           a clearly marked link row (same pattern as "Manage folders…"). -->
      <div class="border-t border-light-border/20 dark:border-dark-border/20 my-1" />

      <button
        ref="itemRefs"
        class="dropdown-item"
        type="button"
        data-testid="link-tool-summarizer"
        @click="goToSummarizer"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon icon="mdi:file-document-outline" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium">{{ $t('chatInput.tools.summarizer') }}</span>
          <div class="text-xs txt-secondary">{{ $t('chatInput.tools.summarizerDesc') }}</div>
        </div>
        <ArrowTopRightOnSquareIcon class="w-4 h-4 flex-shrink-0 txt-secondary" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import {
  ArrowTopRightOnSquareIcon,
  WrenchScrewdriverIcon,
  ChevronUpIcon,
  CheckIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { type Command, useCommandsStore } from '@/stores/commands'
import { getFeaturesStatus, type Feature } from '@/services/featuresService'
import { useRouter } from 'vue-router'

interface Props {
  activeCommand?: string | null
  thinkingEnabled?: boolean
  voiceReply?: boolean
  supportsReasoning?: boolean
  enhanceEnabled?: boolean
  enhanceLoading?: boolean
  /** Enhance needs text in the input to act on. */
  enhanceAvailable?: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  insertCommand: [command: Command]
  toggleThinking: []
  toggleVoiceReply: []
  toggleEnhance: []
}>()

/** Feature-gated tools that insert a slash command into the input. */
const commandTools = [
  {
    id: 'web-search',
    command: 'search',
    icon: 'mdi:web',
    labelKey: 'chatInput.tools.webSearch',
    descKey: 'chatInput.tools.webSearchDesc',
  },
  {
    id: 'image-gen',
    command: 'pic',
    icon: 'mdi:image',
    labelKey: 'chatInput.tools.imageGen',
    descKey: 'chatInput.tools.imageGenDesc',
  },
  {
    id: 'video-gen',
    command: 'vid',
    icon: 'mdi:video',
    labelKey: 'chatInput.tools.videoGen',
    descKey: 'chatInput.tools.videoGenDesc',
  },
] as const

const router = useRouter()
const commandsStore = useCommandsStore()
const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])
const dropdownRef = ref<HTMLElement | null>(null)
const featuresStatus = ref<Record<string, Feature>>({})
const isLoadingFeatures = ref(true)

// Same breakpoint as ChatInput's isMobile (innerWidth < 768).
const mobileMq = window.matchMedia('(max-width: 767px)')
const isMobileViewport = ref(mobileMq.matches)
const onMobileMqChange = (e: MediaQueryListEvent) => (isMobileViewport.value = e.matches)

const activeToggleCount = computed(
  () => Number(props.thinkingEnabled ?? false) + Number(props.voiceReply ?? false)
)

const isToolActive = (commandName: string): boolean => {
  return props.activeCommand === commandName
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

const selectToolCommand = (toolId: string, commandName: string) => {
  const feature = featuresStatus.value[toolId]

  // If feature is disabled, navigate to setup instructions instead
  if (feature && !feature.enabled && feature.setup_required) {
    router.push({
      path: '/settings',
      query: { tab: 'features', feature: toolId },
    })
    closeDropdown()
    return
  }

  // Get the command from the store and emit it
  const command = commandsStore.getCommand(commandName)
  if (command) {
    emit('insertCommand', command)
  }

  // Close dropdown after selection
  closeDropdown()
}

const goToSummarizer = () => {
  closeDropdown()
  router.push('/ai/summarizer')
}

// Close after triggering so the rewritten text in the input is visible.
const handleEnhance = () => {
  emit('toggleEnhance')
  closeDropdown()
}

const focusNext = () => {
  const currentIndex = itemRefs.value.findIndex((el) => el === document.activeElement)
  const nextIndex = (currentIndex + 1) % itemRefs.value.length
  itemRefs.value[nextIndex]?.focus()
}

const focusPrevious = () => {
  const currentIndex = itemRefs.value.findIndex((el) => el === document.activeElement)
  const prevIndex = currentIndex <= 0 ? itemRefs.value.length - 1 : currentIndex - 1
  itemRefs.value[prevIndex]?.focus()
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!isOpen.value) return

  // Check if click is inside the dropdown container
  if (dropdownRef.value && dropdownRef.value.contains(target)) {
    return
  }

  // Check if click is on chat-related elements (input, messages area, etc.)
  const chatElements = target.closest(
    '[data-testid="comp-chat-input"], [data-testid="section-messages"], [data-testid="input-chat-message"], [data-testid="comp-chat-input-shell"]'
  )
  if (chatElements) {
    closeDropdown()
    return
  }

  // Close if click is outside dropdown
  closeDropdown()
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  mobileMq.addEventListener('change', onMobileMqChange)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  mobileMq.removeEventListener('change', onMobileMqChange)
})
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
