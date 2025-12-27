<template>
  <div
    class="sticky bottom-0 bg-chat-input-area pb-[env(safe-area-inset-bottom)]"
    data-testid="comp-chat-input"
    @paste="handlePaste"
  >
    <div class="max-w-4xl mx-auto px-4 py-4">
      <!-- Active Command and File Display (above input) -->
      <div v-if="activeCommand || uploadedFiles.length > 0" class="mb-3 flex flex-wrap gap-2">
        <!-- Uploaded Files -->
        <div
          v-for="(file, index) in uploadedFiles"
          :key="'file-' + index"
          class="flex items-center gap-2 px-3 py-2 surface-chip rounded-lg"
        >
          <Icon :icon="getFileIcon(file.file_type || file.name || '')" class="w-4 h-4" />
          <span class="text-sm txt-secondary">{{ file.filename || file.name }}</span>
          <span v-if="file.processing" class="text-xs txt-muted">(processing...)</span>
          <button
            class="icon-ghost p-0 min-w-0 w-auto h-auto"
            aria-label="Remove file"
            :disabled="file.processing"
            @click="removeFile(index)"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>

        <!-- Active Command -->
        <button
          v-if="activeCommand"
          type="button"
          :class="[
            'pill text-xs flex items-center gap-2',
            isCommandValid
              ? 'pill--active'
              : 'bg-orange-500/10 border-orange-500/30 text-orange-600 dark:text-orange-400',
          ]"
          data-testid="btn-chat-command-clear"
          @click="clearCommand"
        >
          <Icon :icon="commandIcon" class="w-4 h-4" />
          <span class="font-mono font-semibold">/{{ activeCommand }}</span>
          <XMarkIcon class="w-4 h-4" />
        </button>
      </div>

      <div
        class="relative surface-card"
        :class="{ 'ring-2 ring-primary': isDragging }"
        data-testid="comp-chat-input-shell"
        @dragover.prevent="handleDragOver"
        @dragleave.prevent="handleDragLeave"
        @drop.prevent="handleDrop"
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
              class="flex-1"
              data-testid="input-chat-message"
              @keydown="handleKeyDown"
              @keydown.enter.exact.prevent="sendMessage"
              @focus="isFocused = true"
              @blur="isFocused = false"
            />
          </div>
        </div>

        <!-- Fixed file upload button (positioned absolutely) -->
        <div
          class="absolute bottom-2 left-3 md:left-4 pointer-events-none"
          data-testid="section-chat-attachments"
        >
          <button
            type="button"
            class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-xl pointer-events-auto"
            :aria-label="$t('chatInput.attach')"
            :disabled="uploading"
            data-testid="btn-chat-attach"
            @click="triggerFileUpload"
          >
            <Icon v-if="uploading" icon="mdi:loading" class="w-5 h-5 animate-spin" />
            <PlusIcon v-else class="w-5 h-5" />
          </button>

          <input
            ref="fileInputRef"
            type="file"
            multiple
            class="hidden"
            accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt,.xlsx,.xls,.pptx,.ppt"
            data-testid="input-chat-file"
            @change="handleFileSelect"
          />
        </div>

        <!-- Fixed action buttons (positioned absolutely) -->
        <div
          class="absolute bottom-2 right-3 md:right-4 flex items-center gap-2 pointer-events-none"
          data-testid="section-chat-primary-actions"
        >
          <button
            v-if="showMicrophoneButton"
            type="button"
            :class="[
              'h-[44px] min-w-[44px] flex items-center justify-center rounded-xl pointer-events-auto',
              isRecording ? 'bg-red-500 hover:bg-red-600' : 'icon-ghost',
            ]"
            :aria-label="$t('chatInput.voice')"
            :title="useWebSpeech ? $t('chatInput.voiceRealtime') : $t('chatInput.voiceWhisper')"
            data-testid="btn-chat-voice"
            @click="toggleRecording"
          >
            <Icon v-if="isRecording" icon="mdi:stop" class="w-5 h-5 text-white" />
            <MicrophoneIcon v-else class="w-5 h-5" />
          </button>

          <button
            type="button"
            :disabled="!isStreaming && !canSend"
            :class="[
              'h-[44px] min-w-[44px] flex items-center justify-center btn-primary pointer-events-auto transition-all',
              isStreaming ? 'rounded' : 'rounded-xl',
            ]"
            :aria-label="isStreaming ? 'Stop' : $t('chatInput.send')"
            data-testid="btn-chat-send"
            @click="isStreaming ? emit('stop') : sendMessage()"
          >
            <div v-if="isStreaming" class="w-4 h-4 bg-white rounded-sm"></div>
            <PaperAirplaneIcon v-else class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Main controls - always visible below input -->
      <div class="mt-3 flex items-center gap-2" data-testid="section-chat-secondary-actions">
        <ToolsDropdown
          :active-command="activeCommand"
          class="flex-shrink-0"
          @insert-command="handleInsertCommand"
        />
        <button
          type="button"
          :class="[
            'pill flex-shrink-0',
            enhanceLoading && 'pill--loading',
            enhanceEnabled && 'pill--active',
          ]"
          :disabled="enhanceLoading"
          :aria-label="$t('chatInput.enhance')"
          data-testid="btn-chat-enhance"
          @click="toggleEnhance"
        >
          <SparklesIcon class="w-4 h-4 md:w-5 md:h-5" />
          <span class="text-xs md:text-sm font-medium hidden sm:inline">{{
            $t('chatInput.enhance')
          }}</span>
        </button>
        <button
          type="button"
          :disabled="!supportsReasoning"
          :class="[
            'pill flex-shrink-0',
            thinkingEnabled && 'pill--active',
            !supportsReasoning && 'opacity-50 cursor-not-allowed',
          ]"
          :aria-label="$t('chatInput.thinking')"
          data-testid="btn-chat-thinking"
          @click="toggleThinking"
        >
          <Icon icon="mdi:brain" class="w-4 h-4 md:w-5 md:h-5" />
          <span class="text-xs md:text-sm font-medium hidden sm:inline">{{
            $t('chatInput.thinking')
          }}</span>
        </button>
      </div>
    </div>

    <!-- File Selection Modal -->
    <FileSelectionModal
      :visible="fileSelectionModalVisible"
      @close="fileSelectionModalVisible = false"
      @select="handleFilesSelected"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, type Ref } from 'vue'
import {
  PaperAirplaneIcon,
  XMarkIcon,
  SparklesIcon,
  MicrophoneIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import Textarea from './Textarea.vue'
import CommandPalette from './CommandPalette.vue'
import ToolsDropdown from './ToolsDropdown.vue'
import FileSelectionModal from './FileSelectionModal.vue'
import { parseCommand } from '../commands/parse'
import { useCommandsStore, type Command } from '@/stores/commands'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useNotification } from '@/composables/useNotification'
import { chatApi } from '@/services/api/chatApi'
import type { FileItem } from '@/services/filesService'
import { AudioRecorder } from '@/services/audioRecorder'
import { WebSpeechService, isWebSpeechSupported } from '@/services/webSpeechService'
import { useConfigStore } from '@/stores/config'
import { useI18n } from 'vue-i18n'
import { useAutoPersist } from '@/composables/useInputPersistence'
import { useChatsStore } from '@/stores/chats'

interface UploadedFile {
  file_id: number
  filename: string
  file_type: string
  name?: string
  processing: boolean
}

interface Props {
  isStreaming?: boolean
}

const props = defineProps<Props>()

const isStreaming = computed(() => props.isStreaming ?? false)

const message = ref('')
const originalMessage = ref('')
const enhancedMessage = ref('')
const uploadedFiles = ref<UploadedFile[]>([])
const uploading = ref(false)
const enhanceEnabled = ref(false)
const enhanceLoading = ref(false)
const thinkingEnabled = ref(false)
const paletteVisible = ref(false)
const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null)
const textareaRef = ref<InstanceType<typeof Textarea> | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const activeCommand = ref<string | null>(null)
const isDragging = ref(false)
const isFocused = ref(false)
const isMobile = ref(window.innerWidth < 768)
const isRecording = ref(false)
const audioRecorder = ref<AudioRecorder | null>(null)
const webSpeechService = ref<WebSpeechService | null>(null)
const interimTranscript = ref('')
const speechBaseMessage = ref('') // Message content before recording started
const speechFinalTranscript = ref('') // Accumulated final transcripts during recording
const fileSelectionModalVisible = ref(false)

const aiConfigStore = useAiConfigStore()
const chatsStore = useChatsStore()
const configStore = useConfigStore()
const { warning, error: showError, success } = useNotification()
const { t } = useI18n()

/**
 * Determine if microphone button should be shown.
 * Show when: Web Speech API is supported OR whisperEnabled is true.
 * Hidden only when: neither is available.
 */
const showMicrophoneButton = computed(() => {
  const whisperEnabled = configStore.speech.whisperEnabled
  const webSpeechSupported = isWebSpeechSupported()

  // Show if either option is available
  return webSpeechSupported || whisperEnabled
})

/**
 * Determine which speech recognition method to use.
 * Priority: Web Speech API FIRST (real-time streaming), Whisper as fallback.
 *
 * - Web Speech API: Real-time streaming, works in Chrome/Edge/Safari
 * - Whisper backend: Record-then-transcribe, works everywhere
 */
const useWebSpeech = computed(() => {
  // Web Speech API has PRIORITY - use it whenever available
  // This gives real-time streaming text as user speaks
  return isWebSpeechSupported()
})

// Input persistence - auto-save with proper debouncing
const { clearInput: clearPersistedInput } = useAutoPersist(
  message,
  'chat',
  computed(() => chatsStore.activeChatId)
)

const emit = defineEmits<{
  send: [
    message: string,
    options?: { includeReasoning?: boolean; webSearch?: boolean; fileIds?: number[] },
  ]
  stop: []
}>()

const commandsStore = useCommandsStore()

const isCommandValid = computed(() => {
  if (!activeCommand.value) return false
  return commandsStore.commands.some((cmd) => cmd.name === activeCommand.value)
})

const commandIcon = computed(() => {
  if (!activeCommand.value) return 'mdi:help-circle'
  const cmd = commandsStore.commands.find((c) => c.name === activeCommand.value)
  return cmd?.icon || 'mdi:help-circle'
})

const canSend = computed(() => {
  const trimmedMessage = message.value.trim()
  const hasMessage = trimmedMessage.length > 0
  const hasFiles = uploadedFiles.value.length > 0
  const filesReady = uploadedFiles.value.every((f) => !f.processing)

  // Prevent sending if only a command is present (e.g., just "/pic" without arguments)
  const isOnlyCommand = trimmedMessage.startsWith('/') && !trimmedMessage.includes(' ')
  if (isOnlyCommand && !hasFiles) {
    return false
  }

  return (hasMessage || hasFiles) && filesReady && !uploading.value
})

const supportsReasoning = computed(() => {
  // Get the configured default model
  const currentModel = aiConfigStore.getCurrentModel('CHAT')

  // If no model yet (store still loading), return false (button will be disabled)
  if (!currentModel) {
    return false
  }

  // Check if model has reasoning capability
  return currentModel.features?.includes('reasoning') ?? false
})

// Auto-enable thinking when switching to a reasoning-capable model
watch(
  supportsReasoning,
  (newValue) => {
    if (newValue) {
      thinkingEnabled.value = true
    } else {
      thinkingEnabled.value = false
    }
  },
  { immediate: true }
)

watch(
  message,
  (newValue) => {
    if (newValue.startsWith('/')) {
      // Only show palette if no space (still typing command) or only command without args
      const hasSpace = newValue.includes(' ')
      const parsed = parseCommand(newValue)

      if (parsed) {
        activeCommand.value = parsed.command
        // Hide palette if user has started typing arguments (command + space)
        paletteVisible.value = !hasSpace || parsed.args.length === 0
      } else {
        activeCommand.value = null
        paletteVisible.value = true
      }
    } else {
      paletteVisible.value = false
      activeCommand.value = null
    }

    // Auto-disable enhance if message has been edited (differs from enhanced version)
    if (enhanceEnabled.value && enhancedMessage.value) {
      const currentText = newValue.trim()
      const enhancedText = enhancedMessage.value.trim()

      // If message is empty (deleted) or differs from enhanced version, disable enhance
      if (!currentText || currentText !== enhancedText) {
        enhanceEnabled.value = false
        originalMessage.value = ''
        enhancedMessage.value = ''
      }
    }

    // Note: Input persistence happens automatically via useAutoPersist (debounced 500ms)
  },
  { immediate: false }
)

const sendMessage = () => {
  if (isStreaming.value) {
    warning('Please wait for the current response to finish before sending another message.')
    return
  }

  if (canSend.value) {
    const hasWebSearch = activeCommand.value === 'search'

    // Send the full message with command to backend (it needs it for /pic and /vid)
    // The UI cleanup will happen in ChatView before adding to history
    const messageToSend = message.value

    const options = {
      includeReasoning: thinkingEnabled.value,
      webSearch: hasWebSearch,
      fileIds: uploadedFiles.value.filter((f) => !f.processing).map((f) => f.file_id),
    }
    emit('send', messageToSend, options)
    message.value = ''
    uploadedFiles.value = []
    paletteVisible.value = false
    activeCommand.value = null
    // Reset enhance state after sending
    enhanceEnabled.value = false
    originalMessage.value = ''
    enhancedMessage.value = ''
    // Clear persisted input after successful send
    clearPersistedInput()
  }
}

const toggleThinking = () => {
  // Check if current model supports reasoning
  if (!supportsReasoning.value) {
    warning(t('chatInput.reasoningNotSupported'))
    return
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
  // Focus textarea after command selection
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

const handleInsertCommand = (cmd: Command) => {
  if (cmd.requiresArgs) {
    message.value = `${cmd.usage.split('[')[0].trim()} `
  } else {
    message.value = cmd.usage
  }
  activeCommand.value = cmd.name
  // Focus textarea after command insertion
  nextTick(() => {
    textareaRef.value?.focus()
  })
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
      e.preventDefault()
      e.stopPropagation()
      paletteRef.value.handleKeyDown(e)
    }
  }
}

const removeFile = (index: number) => {
  uploadedFiles.value.splice(index, 1)
}

const triggerFileUpload = () => {
  if (uploading.value) return
  // Open file selection modal to choose from existing files
  fileSelectionModalVisible.value = true
}

const handleFilesSelected = async (selectedFiles: FileItem[]) => {
  // Add selected files to uploadedFiles
  selectedFiles.forEach((file) => {
    uploadedFiles.value.push({
      file_id: file.id,
      filename: file.filename,
      file_type: file.file_type,
      processing: false,
    })
  })
  success(`${selectedFiles.length} file(s) attached`)
}

const handleFileSelect = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const files = target.files
  if (files && files.length > 0) {
    await uploadFiles(Array.from(files))
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

const handleDrop = async (event: DragEvent) => {
  isDragging.value = false
  const files = event.dataTransfer?.files
  if (files && files.length > 0) {
    await uploadFiles(Array.from(files))
  }
}

// Clipboard paste handler for images and files
const handlePaste = async (event: ClipboardEvent) => {
  const items = event.clipboardData?.items
  if (!items) return

  const filesToUpload: File[] = []

  for (const item of items) {
    // Handle pasted images (screenshots, copied images)
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) {
        // Generate a meaningful filename for pasted images
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5)
        const extension = item.type.split('/')[1] || 'png'
        const namedFile = new File([file], `pasted-image-${timestamp}.${extension}`, {
          type: file.type,
        })
        filesToUpload.push(namedFile)
      }
    }
    // Handle pasted files (from file manager)
    else if (item.kind === 'file') {
      const file = item.getAsFile()
      if (file) {
        filesToUpload.push(file)
      }
    }
  }

  if (filesToUpload.length > 0) {
    // Prevent default paste behavior for files/images
    event.preventDefault()
    try {
      await uploadFiles(filesToUpload)
      success(t('chatInput.filesPasted', { count: filesToUpload.length }))
    } catch (err) {
      showError(t('chatInput.uploadError'))
    }
  }
  // If no files, let the default paste behavior handle text
}

const uploadFiles = async (files: File[]) => {
  uploading.value = true

  for (const file of files) {
    // Add to UI immediately as "processing"
    const tempFile: UploadedFile = {
      file_id: 0,
      filename: file.name,
      file_type: file.name.split('.').pop() || 'unknown',
      name: file.name,
      processing: true,
    }
    uploadedFiles.value.push(tempFile)

    try {
      // Upload to backend (PreProcessor extracts content automatically)
      const result = await chatApi.uploadChatFile(file)

      // Update with real file_id
      const index = uploadedFiles.value.findIndex((f) => f.name === file.name && f.processing)
      if (index !== -1) {
        uploadedFiles.value[index] = {
          file_id: result.file_id,
          filename: result.filename,
          file_type: result.file_type,
          processing: false,
        }
      }

      // For audio files, show transcription info if available
      if (result.text) {
        const preview = result.text.substring(0, 50) + (result.text.length > 50 ? '...' : '')
        const languageInfo = result.language ? ` (${result.language})` : ''
        success(`🎙️ Audio transcribed${languageInfo}: "${preview}"`)
      }

      console.log('✅ File uploaded and processed:', result)
    } catch (err: any) {
      console.error('❌ File upload failed:', err)
      showError(`File upload failed: ${err.message}`)

      // Remove from list
      const index = uploadedFiles.value.findIndex((f) => f.name === file.name)
      if (index !== -1) {
        uploadedFiles.value.splice(index, 1)
      }
    }
  }

  uploading.value = false
}

/**
 * Toggle speech recording using hybrid approach.
 * Uses Web Speech API for real-time transcription when available and whisperEnabled=false.
 * Falls back to Whisper.cpp backend recording when whisperEnabled=true.
 */
const toggleRecording = async () => {
  if (isRecording.value) {
    // Stop recording (either Web Speech or AudioRecorder)
    if (webSpeechService.value) {
      // Stop triggers onEnd callback which finalizes the message
      webSpeechService.value.stop()
      webSpeechService.value = null
    }
    if (audioRecorder.value) {
      audioRecorder.value.stopRecording()
    }
    isRecording.value = false
    return
  }

  // Start recording - Web Speech API has priority for real-time streaming
  if (useWebSpeech.value) {
    console.log('🎙️ Using Web Speech API (real-time streaming)')
    await startWebSpeechRecording()
  } else {
    console.log('🎙️ Using Whisper backend (record-then-transcribe)')
    await startWhisperRecording()
  }
}

/**
 * Start real-time speech recognition using Web Speech API.
 * Text appears in input field live as user speaks.
 *
 * How it works:
 * - speechBaseMessage: Original text in input before recording started
 * - speechFinalTranscript: Accumulated finalized phrases during recording
 * - interimTranscript: Current partial phrase (updates rapidly as user speaks)
 * - message.value: Always shows baseMessage + finalTranscript + interimTranscript
 */
const startWebSpeechRecording = async () => {
  try {
    // Save current message as base (text before recording)
    speechBaseMessage.value = message.value
    speechFinalTranscript.value = ''
    interimTranscript.value = ''

    console.log(
      '🎙️ Web Speech: Starting with base message:',
      JSON.stringify(speechBaseMessage.value)
    )

    webSpeechService.value = new WebSpeechService({
      language: navigator.language,
      interimResults: true,
      continuous: true,
      onStart: () => {
        console.log('🎙️ Web Speech: onStart fired')
        isRecording.value = true
        success(t('chatInput.listeningStarted'))
      },
      onEnd: () => {
        console.log('🎙️ Web Speech: onEnd fired')
        isRecording.value = false
        // Finalize: set message to base + finals + any remaining interim
        const base = speechBaseMessage.value
        const finals = speechFinalTranscript.value
        const interim = interimTranscript.value
        const separator = base && (finals || interim) ? ' ' : ''
        message.value = base + separator + finals + (finals && interim ? ' ' : '') + interim

        console.log('🎙️ Web Speech: Final message:', JSON.stringify(message.value))

        // Reset speech tracking
        speechBaseMessage.value = ''
        speechFinalTranscript.value = ''
        interimTranscript.value = ''
      },
      onResult: (text: string, isFinal: boolean) => {
        console.log(`🎙️ Web Speech: onResult - "${text}" (isFinal: ${isFinal})`)

        const base = speechBaseMessage.value
        const separator = base ? ' ' : ''

        if (isFinal) {
          // Final result - add to accumulated finals
          const finalSeparator = speechFinalTranscript.value ? ' ' : ''
          speechFinalTranscript.value += finalSeparator + text
          interimTranscript.value = ''

          // Update input field: base + all finals
          message.value = base + separator + speechFinalTranscript.value
          console.log('🎙️ Final result, message now:', JSON.stringify(message.value))
        } else {
          // Interim result - update live in input field
          interimTranscript.value = text
          const finals = speechFinalTranscript.value
          const interimSeparator = finals ? ' ' : ''

          // Update input field: base + finals + interim (real-time!)
          message.value = base + separator + finals + interimSeparator + text
          console.log('🎙️ Interim result, message now:', JSON.stringify(message.value))
        }
      },
      onError: (error) => {
        console.error('❌ Web Speech error:', error)
        // Only show error for actual problems, not 'no-speech' during pauses
        if (error.type !== 'no_speech') {
          showError(error.userMessage)
        }
        isRecording.value = false
      },
    })

    await webSpeechService.value.start()
    console.log('🎙️ Web Speech: start() completed')
  } catch (err: unknown) {
    console.error('❌ Failed to start Web Speech:', err)
    const errMessage = err instanceof Error ? err.message : 'Unknown error'
    showError(t('chatInput.speechError', { error: errMessage }))
    isRecording.value = false
  }
}

/**
 * Start audio recording for Whisper.cpp backend transcription.
 * Records audio blob, uploads to backend for processing.
 */
const startWhisperRecording = async () => {
  try {
    audioRecorder.value = new AudioRecorder({
      onStart: () => {
        isRecording.value = true
        success(t('chatInput.recordingStarted'))
      },
      onStop: () => {
        isRecording.value = false
      },
      onDataAvailable: async (audioBlob: Blob) => {
        console.log('🎵 Audio recorded:', audioBlob.size, 'bytes')
        await transcribeAudio(audioBlob)
      },
      onError: (error) => {
        console.error('❌ Recording error:', error)
        showError(error.userMessage)
        isRecording.value = false
      },
    })

    // Check support first (with detailed diagnostics)
    const support = await audioRecorder.value.checkSupport()
    if (!support.supported || !support.hasDevices) {
      if (support.error) {
        showError(support.error.userMessage)
      }
      return
    }

    // Start recording
    await audioRecorder.value.startRecording()
  } catch (err: unknown) {
    console.error('❌ Failed to start recording:', err)
    const error = err as { userMessage?: string; message?: string }
    showError(
      error.userMessage ||
        t('chatInput.recordingError', { error: error.message || 'Unknown error' })
    )
    isRecording.value = false
  }
}

/**
 * Transcribe audio blob using Whisper.cpp backend.
 * Called after AudioRecorder stops and provides recorded audio.
 */
const transcribeAudio = async (audioBlob: Blob) => {
  uploading.value = true

  try {
    // Upload for transcription (WhisperCPP on backend)
    const result = await chatApi.transcribeAudio(audioBlob)

    // CRITICAL: Add transcribed text directly to message input!
    if (result.text) {
      // Insert text with space separator if there's existing text
      message.value += (message.value ? ' ' : '') + result.text

      // Show success notification with preview
      const preview = result.text.substring(0, 50) + (result.text.length > 50 ? '...' : '')
      const languageInfo = result.language ? ` (${result.language})` : ''
      success(t('chatInput.transcribed', { language: languageInfo, preview }))

      console.log('✅ Audio transcribed:', {
        text_length: result.text.length,
        language: result.language,
        duration: result.duration,
      })

      // Focus textarea for immediate editing
      nextTick(() => {
        textareaRef.value?.focus()
      })
    } else {
      warning(t('chatInput.noSpeechDetected'))
    }
  } catch (err: unknown) {
    console.error('❌ Transcription failed:', err)
    const error = err as { message?: string }
    showError(t('chatInput.transcriptionFailed', { error: error.message || 'Unknown error' }))
  } finally {
    isRecording.value = false
    uploading.value = false
  }
}

const getFileIcon = (fileType: string): string => {
  const ext = fileType.toLowerCase()
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'mdi:image'
  if (['mp4', 'webm', 'mov', 'avi'].includes(ext)) return 'mdi:video'
  if (['mp3', 'wav', 'ogg', 'm4a', 'flac', 'opus'].includes(ext)) return 'mdi:microphone'
  if (['pdf'].includes(ext)) return 'mdi:file-pdf'
  if (['doc', 'docx'].includes(ext)) return 'mdi:file-word'
  if (['xls', 'xlsx'].includes(ext)) return 'mdi:file-excel'
  if (['ppt', 'pptx'].includes(ext)) return 'mdi:file-powerpoint'
  if (['txt'].includes(ext)) return 'mdi:file-document'
  return 'mdi:file'
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
    // Only restore original message if current message still matches the enhanced version
    // If message was deleted or edited, don't restore
    const currentText = message.value.trim()
    const enhancedText = enhancedMessage.value.trim()

    // Only restore if message still matches enhanced version (not empty, not edited)
    if (currentText && currentText === enhancedText) {
      message.value = originalMessage.value
    }

    originalMessage.value = ''
    enhancedMessage.value = ''
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
    enhancedMessage.value = result.enhanced
    message.value = result.enhanced
    enhanceEnabled.value = true
  } catch (err: any) {
    // Show detailed error message if available
    const errorMsg = err.response?.data?.message || err.message || 'Failed to enhance message'
    showError(errorMsg)
    console.error('Enhancement error:', err)
  } finally {
    enhanceLoading.value = false
  }
}

// Expose textarea ref and uploadFiles for parent component
// ATTENTION: needs to be typed when using vue-tsc -b
defineExpose<{
  textareaRef: Ref<InstanceType<typeof Textarea> | null>
  uploadFiles: (files: File[]) => Promise<void>
}>({
  textareaRef,
  uploadFiles,
})
</script>
