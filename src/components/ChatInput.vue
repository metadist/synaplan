<template>
  <div class="sticky bottom-0 bg-chat-input-area pb-[env(safe-area-inset-bottom)]">
    <div class="max-w-4xl mx-auto px-4 py-4">
      <!-- Active Tools and Command Display (above input) -->
      <div v-if="activeTools.length > 0 || activeCommand || attachments.length > 0" class="mb-3 flex flex-wrap gap-2">
        <!-- Attachments -->
        <div
          v-for="(attachment, index) in attachments"
          :key="'att-' + index"
          class="flex items-center gap-2 px-3 py-2 surface-chip rounded-lg"
        >
          <span class="text-sm txt-secondary">{{ attachment }}</span>
          <button
            @click="removeAttachment(index)"
            class="icon-ghost p-0 min-w-0 w-auto h-auto"
            aria-label="Remove attachment"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>

        <!-- Active Tools -->
        <div 
          v-for="tool in activeTools" 
          :key="'tool-' + tool.id"
          class="pill pill--active text-xs flex items-center gap-2"
        >
          <Icon :icon="tool.icon" class="w-4 h-4" />
          <span class="font-medium">{{ tool.name }}</span>
          <button
            @click="removeTool(tool.id)"
            type="button"
            class="hover:opacity-75 transition-opacity"
            :aria-label="$t('chatInput.removeTool')"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>

        <!-- Active Command -->
        <button
          v-if="activeCommand"
          @click="clearCommand"
          type="button"
          :class="[
            'pill text-xs flex items-center gap-2',
            isCommandValid ? 'pill--active' : 'bg-orange-500/10 border-orange-500/30 text-orange-600 dark:text-orange-400'
          ]"
        >
          <Icon :icon="commandIcon" class="w-4 h-4" />
          <span class="font-mono font-semibold">/{{ activeCommand }}</span>
          <XMarkIcon class="w-4 h-4" />
        </button>
      </div>

      <div 
        class="relative surface-card"
        @dragover.prevent="handleDragOver"
        @dragleave.prevent="handleDragLeave"
        @drop.prevent="handleDrop"
        :class="{ 'ring-2 ring-primary': isDragging }"
      >
        <!-- Command Palette (outside overflow container) -->
        <CommandPalette
          ref="paletteRef"
          :visible="paletteVisible"
          :query="message"
          @select="handleCommandSelect"
          @close="closePalette"
        />

        <!-- Scrollable container with padding for scrollbar alignment -->
        <div class="max-h-[40vh] overflow-y-auto chat-input-scroll">
          <div class="pl-[60px] pr-[140px] py-2">
            <!-- Textarea -->
            <Textarea
              ref="textareaRef"
              v-model="message"
              :placeholder="isMobile ? 'Message...' : $t('chatInput.placeholder')"
              :rows="1"
              @keydown="handleKeyDown"
              @keydown.enter.exact.prevent="sendMessage"
              @focus="isFocused = true"
              @blur="isFocused = false"
              class="flex-1"
            />
          </div>
        </div>

        <!-- Fixed file upload button (positioned absolutely) -->
        <div class="absolute bottom-2 left-3 md:left-4 pointer-events-none">
          <button
            @click="triggerFileUpload"
            type="button"
            class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-xl pointer-events-auto"
            :aria-label="$t('chatInput.attach')"
          >
            <PlusIcon class="w-5 h-5" />
          </button>

          <input
            ref="fileInputRef"
            type="file"
            multiple
            @change="handleFileSelect"
            class="hidden"
            accept="image/*,video/*,.pdf,.doc,.docx,.txt"
          />
        </div>

        <!-- Fixed action buttons (positioned absolutely) -->
        <div class="absolute bottom-2 right-3 md:right-4 flex items-center gap-2 pointer-events-none">
          <button
            type="button"
            class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-xl pointer-events-auto"
            :aria-label="$t('chatInput.voice')"
          >
            <MicrophoneIcon class="w-5 h-5" />
          </button>

          <button
            @click="isStreaming ? emit('stop') : sendMessage()"
            type="button"
            :disabled="!isStreaming && !message.trim()"
            :class="[
              'h-[44px] min-w-[44px] flex items-center justify-center btn-primary pointer-events-auto transition-all',
              isStreaming ? 'rounded' : 'rounded-xl'
            ]"
            :aria-label="isStreaming ? 'Stop' : $t('chatInput.send')"
          >
            <div v-if="isStreaming" class="w-4 h-4 bg-white rounded-sm"></div>
            <PaperAirplaneIcon v-else class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Main controls - always visible below input -->
      <div class="mt-3 flex items-center gap-2">
        <ModelSelect class="flex-shrink-0" />
        <ToolsDropdown 
          :active-tools="activeTools" 
          @select="toggleTool" 
          @remove="removeTool"
          class="flex-shrink-0"
        />
        <button
          @click="toggleEnhance"
          type="button"
          :class="[
            'pill flex-shrink-0',
            enhanceLoading && 'pill--loading',
            enhanceEnabled && 'pill--active'
          ]"
          :disabled="enhanceLoading"
          :aria-label="$t('chatInput.enhance')"
        >
          <SparklesIcon class="w-4 h-4 md:w-5 md:h-5" />
          <span class="text-xs md:text-sm font-medium hidden sm:inline">{{ $t('chatInput.enhance') }}</span>
        </button>
        <button
          @click="toggleThinking"
          type="button"
          :class="[
            'pill flex-shrink-0',
            thinkingEnabled && 'pill--active'
          ]"
          :aria-label="$t('chatInput.thinking')"
        >
          <Icon icon="mdi:brain" class="w-4 h-4 md:w-5 md:h-5" />
          <span class="text-xs md:text-sm font-medium hidden sm:inline">{{ $t('chatInput.thinking') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { PaperAirplaneIcon, XMarkIcon, SparklesIcon, MicrophoneIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import Textarea from './Textarea.vue'
import ModelSelect from './ModelSelect.vue'
import CommandPalette from './CommandPalette.vue'
import ToolsDropdown from './ToolsDropdown.vue'
import { parseCommand } from '../commands/parse'
import { useCommandsStore, type Command } from '@/stores/commands'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useNotification } from '@/composables/useNotification'
import { chatApi } from '@/services/api/chatApi'

interface Tool {
  id: string
  name: string
  icon: string
}

interface Props {
  isStreaming?: boolean
}

defineProps<Props>()

const message = ref('')
const originalMessage = ref('')
const attachments = ref<string[]>([])
const enhanceEnabled = ref(false)
const enhanceLoading = ref(false)
const thinkingEnabled = ref(false)
const paletteVisible = ref(false)
const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null)
const textareaRef = ref<InstanceType<typeof Textarea> | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const activeCommand = ref<string | null>(null)
const activeTools = ref<Tool[]>([])
const isDragging = ref(false)
const isFocused = ref(false)
const isMobile = ref(window.innerWidth < 768)

const aiConfigStore = useAiConfigStore()
const { warning, error: showError } = useNotification()

const emit = defineEmits<{
  send: [message: string, options?: { includeReasoning?: boolean; webSearch?: boolean }]
  stop: []
}>()

const commandsStore = useCommandsStore()

const isCommandValid = computed(() => {
  if (!activeCommand.value) return false
  return commandsStore.commands.some(cmd => cmd.name === activeCommand.value)
})

const commandIcon = computed(() => {
  if (!activeCommand.value) return 'mdi:help-circle'
  const cmd = commandsStore.commands.find(c => c.name === activeCommand.value)
  return cmd?.icon || 'mdi:help-circle'
})

watch(message, (newValue) => {
  if (newValue.startsWith('/')) {
    paletteVisible.value = true
    const parsed = parseCommand(newValue)
    if (parsed) {
      activeCommand.value = parsed.command
    } else {
      activeCommand.value = null
    }
  } else {
    paletteVisible.value = false
    activeCommand.value = null
  }
}, { immediate: false })

const sendMessage = () => {
  if (message.value.trim()) {
    const hasWebSearch = activeTools.value.some(t => t.id === 'web-search')
    
    const options = {
      includeReasoning: thinkingEnabled.value,
      webSearch: hasWebSearch
    }
    emit('send', message.value, options)
    message.value = ''
    paletteVisible.value = false
    activeCommand.value = null
  }
}

const toggleThinking = () => {
  const currentModel = aiConfigStore.getCurrentModel('CHAT')
  
  if (!thinkingEnabled.value) {
    if (!currentModel?.features?.includes('reasoning')) {
      warning('Your current chat model does not support reasoning. Please change your model in /config/ai-models to use this feature.')
      return
    }
  }
  
  thinkingEnabled.value = !thinkingEnabled.value
}

const handleCommandSelect = (cmd: Command) => {
  if (cmd.requiresArgs) {
    message.value = `${cmd.usage.split('[')[0].trim()} `
  } else {
    message.value = cmd.usage
  }
  paletteVisible.value = false
  activeCommand.value = cmd.name
}

const closePalette = () => {
  paletteVisible.value = false
}

const clearCommand = () => {
  activeCommand.value = null
  message.value = ''
  paletteVisible.value = false
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (paletteVisible.value && paletteRef.value) {
    const handled = ['ArrowUp', 'ArrowDown', 'Enter', 'Escape', 'Tab']
    if (handled.includes(e.key)) {
      paletteRef.value.handleKeyDown(e)
    }
  }
}

const removeAttachment = (index: number) => {
  attachments.value.splice(index, 1)
}

const triggerFileUpload = () => {
  fileInputRef.value?.click()
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const files = target.files
  if (files) {
    for (let i = 0; i < files.length; i++) {
      attachments.value.push(files[i].name)
    }
  }
  // Reset input
  target.value = ''
}

const handleDragOver = () => {
  isDragging.value = true
}

const handleDragLeave = () => {
  isDragging.value = false
}

const handleDrop = (event: DragEvent) => {
  isDragging.value = false
  const files = event.dataTransfer?.files
  if (files) {
    for (let i = 0; i < files.length; i++) {
      attachments.value.push(files[i].name)
    }
  }
}

const toggleTool = (toolId: string) => {
  const existingIndex = activeTools.value.findIndex(t => t.id === toolId)
  
  if (existingIndex >= 0) {
    // Tool is active, remove it
    activeTools.value.splice(existingIndex, 1)
  } else {
    // Tool is not active, add it
    const toolDefinitions: Record<string, Tool> = {
      'web-search': { id: 'web-search', name: 'Web Search', icon: 'mdi:web' },
      'image-gen': { id: 'image-gen', name: 'Image Generation', icon: 'mdi:image' },
      'code-interpreter': { id: 'code-interpreter', name: 'Code', icon: 'mdi:code-braces' },
    }
    
    const tool = toolDefinitions[toolId]
    if (tool) {
      activeTools.value.push(tool)
    }
  }
}

const removeTool = (toolId: string) => {
  const index = activeTools.value.findIndex(t => t.id === toolId)
  if (index >= 0) {
    activeTools.value.splice(index, 1)
  }
}

const updateIsMobile = () => {
  isMobile.value = window.innerWidth < 768
}

if (typeof window !== 'undefined') {
  window.addEventListener('resize', updateIsMobile)
}

const toggleEnhance = async () => {
  if (enhanceLoading.value) return

  if (enhanceEnabled.value) {
    message.value = originalMessage.value
    originalMessage.value = ''
    enhanceEnabled.value = false
    return
  }

  const currentText = message.value.trim()
  if (!currentText) {
    warning('Please enter a message first')
    return
  }

  enhanceLoading.value = true

  try {
    const result = await chatApi.enhanceMessage(currentText)
    originalMessage.value = currentText
    message.value = result.enhanced
    enhanceEnabled.value = true
  } catch (err) {
    showError('Failed to enhance message')
    console.error('Enhancement error:', err)
  } finally {
    enhanceLoading.value = false
  }
}
</script>
