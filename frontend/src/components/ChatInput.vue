<template>
  <!--
    Sticky chat input bar — `position: sticky; bottom: 0` keeps it pinned to
    the bottom of the scroll area and, on iOS, follows the soft keyboard via
    the visual viewport.

    The composer is the bottom-most element on every breakpoint (the mobile
    push-drawer is an overlay, not a bottom bar), so it owns the iOS home-
    indicator inset. That inset lives in `.chat-composer-sticky` (style.css) and
    collapses to 0 while the keyboard is up so the input sits right above it —
    driven by `--keyboard-inset-height` (native) with `--kb-open` as the web
    fallback, because Keyboard.resize:'none' means visualViewport never shrinks
    in the native app.
  -->
  <div
    :class="
      isCentered
        ? 'bg-chat-input-area'
        : [
            'chat-composer-sticky bg-chat-input-area',
            { 'chat-composer-sticky--kb-open': keyboardOpen },
          ]
    "
    data-testid="comp-chat-input"
    @paste="handlePaste"
  >
    <!-- On mobile the horizontal padding matches the drawer toggle's left
         offset (left-3 = 12px) so the composer aligns with the menu button and
         uses the full width; md+ keeps the roomier px-4. -->
    <div class="max-w-4xl mx-auto py-2 md:py-4" :class="{ 'px-3 md:px-4': !isCentered }">
      <!-- File and Quote Display (above input) -->
      <div v-if="uploadedFiles.length > 0 || quote" class="mb-3 flex flex-wrap gap-2">
        <!-- Quoted reference chip -->
        <QuoteChip v-if="quote" :quote="quote" @remove="emit('clearQuote')" />

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
      </div>

      <!-- Attached banner (e.g. guest message counter) glued to the input's top edge. -->
      <slot name="banner" />

      <div
        class="relative surface-card"
        :class="[
          { 'ring-2 ring-primary': isDragging },
          bannerVisible ? 'max-sm:!rounded-t-none' : '',
        ]"
        data-testid="comp-chat-input-shell"
        @dragover.prevent="handleDragOver"
        @dragleave.prevent="handleDragLeave"
      >
        <!-- Command Palette (outside overflow container) -->
        <CommandPalette
          ref="paletteRef"
          :visible="paletteVisible"
          :query="message"
          @select="handleCommandSelect"
          @close="closePalette"
        />

        <!-- File Mention Palette (@mentions) -->
        <FileMentionPalette
          ref="mentionPaletteRef"
          :visible="mentionPaletteVisible"
          :query="mentionQuery"
          @select="handleMentionSelect"
          @close="mentionPaletteVisible = false"
        />

        <!-- Scrollable container with padding for scrollbar alignment.
             py-2 (16px) + textarea min-h-[40px] = 56px single-line shell, so the
             44px action buttons sit with an even 6px inset on every edge. -->
        <div class="max-h-[40vh] overflow-y-auto chat-input-scroll">
          <!-- The tool badge sits inline at the very start of the input (where
               the caret begins). It stays pinned to the front even when text is
               already present; wrapped text flows in the column beside it. -->
          <div
            class="pl-[56px] py-2 flex items-center gap-2"
            :style="{ paddingRight: `${textareaPaddingRightPx}px` }"
          >
            <ToolBadge
              v-if="activeTool"
              :tool="activeTool"
              class="flex-shrink-0"
              @remove="clearTool"
            />
            <!-- Textarea -->
            <Textarea
              ref="textareaRef"
              v-model="message"
              :placeholder="isMobile ? 'Message...' : $t('chatInput.placeholder')"
              :rows="1"
              class="flex-1 min-w-0"
              data-testid="input-chat-message"
              @keydown="handleKeyDown"
              @focus="isFocused = true"
              @blur="isFocused = false"
            />
          </div>
        </div>

        <!-- Plus menu: attach + per-message controls (Model / Tools / Knowledge).
             Exempt from the guest lock rule — the menu always opens; gated items
             inside surface the guest hint popover. -->
        <div
          ref="plusMenuRef"
          class="absolute bottom-[6px] left-[6px]"
          data-testid="section-chat-plus"
        >
          <button
            type="button"
            :class="[
              'surface-chip icon-ghost h-[44px] min-w-[44px] flex items-center justify-center !rounded-xl relative',
              plusMenuOpen && 'pill--active',
            ]"
            :aria-label="$t('chatInput.plusMenu.label')"
            :aria-expanded="plusMenuOpen"
            :disabled="uploading"
            data-testid="btn-chat-plus"
            @click="togglePlusMenu"
          >
            <Icon v-if="uploading" icon="mdi:loading" class="w-5 h-5 animate-spin" />
            <PlusIcon v-else class="w-5 h-5" />
          </button>

          <div
            v-if="plusMenuOpen"
            class="dropdown-up left-0 min-w-[220px] flex flex-col gap-1"
            data-testid="dropdown-plus-panel"
          >
            <button
              type="button"
              class="dropdown-item"
              data-testid="btn-plus-attach"
              @click="handlePlusAttach"
            >
              <Icon icon="mdi:paperclip" class="w-5 h-5 flex-shrink-0" />
              <span class="text-sm font-medium">{{ $t('chatInput.plusMenu.attach') }}</span>
            </button>

            <template v-if="isGuestMode">
              <button
                type="button"
                class="dropdown-item"
                data-testid="btn-plus-model"
                @click="handlePlusGate('models')"
              >
                <Icon icon="mdi:tune-vertical" class="w-5 h-5 flex-shrink-0" />
                <span class="text-sm font-medium flex-1 text-left">{{
                  $t('chatInput.plusMenu.model')
                }}</span>
                <span class="text-xs txt-muted">{{ $t('chatInput.modelDropdown.default') }}</span>
              </button>
              <button
                type="button"
                class="dropdown-item"
                data-testid="btn-plus-tools"
                @click="handlePlusGate('tools')"
              >
                <Icon icon="mdi:toolbox-outline" class="w-5 h-5 flex-shrink-0" />
                <span class="text-sm font-medium flex-1 text-left">{{
                  $t('chatInput.plusMenu.tools')
                }}</span>
              </button>
              <button
                type="button"
                class="dropdown-item"
                data-testid="btn-plus-knowledge"
                @click="handlePlusGate('knowledge')"
              >
                <Icon icon="mdi:folder-outline" class="w-5 h-5 flex-shrink-0" />
                <span class="text-sm font-medium flex-1 text-left">{{
                  $t('chatInput.plusMenu.knowledge')
                }}</span>
              </button>
            </template>

            <template v-else>
              <ModelDropdown v-model="selectedModelId" />
              <ToolsDropdown
                :active-command="activeTool"
                :thinking-enabled="thinkingEnabled"
                :voice-reply="voiceReply"
                :supports-reasoning="supportsReasoning"
                :enhance-enabled="enhanceEnabled"
                :enhance-loading="enhanceLoading"
                :enhance-available="message.trim().length > 0"
                @insert-command="handleInsertCommand"
                @toggle-thinking="toggleThinking"
                @toggle-voice-reply="toggleVoiceReply"
                @toggle-enhance="toggleEnhance"
              />
              <KnowledgeFolderPicker v-model="selectedGroupKey" :groups="knowledgeGroups" />
            </template>
          </div>

          <input
            type="file"
            multiple
            class="hidden"
            accept="image/*,.heic,.heif,video/*,audio/*,.pdf,.doc,.docx,.txt,.xlsx,.xls,.pptx,.ppt"
            data-testid="input-chat-file"
            @change="handleFileSelect"
          />
        </div>

        <!-- Fixed action buttons (positioned absolutely). Same 6px inset as the
             plus button, and a 6px inter-button gap so every spacing is even. -->
        <div
          class="absolute bottom-[6px] right-[6px] flex items-center gap-1.5 pointer-events-none"
          data-testid="section-chat-primary-actions"
        >
          <button
            v-if="showEnhanceInInput"
            type="button"
            :class="[
              'h-[44px] min-w-[44px] flex items-center justify-center !rounded-xl pointer-events-auto relative',
              enhanceEnabled ? 'pill pill--active' : 'icon-ghost',
              enhanceLoading && 'pill--loading',
            ]"
            :disabled="enhanceLoading"
            :aria-label="$t('chatInput.enhance')"
            :title="$t('chatInput.enhance')"
            data-testid="btn-chat-enhance"
            @click="toggleEnhance"
          >
            <Icon v-if="enhanceLoading" icon="mdi:loading" class="w-5 h-5 animate-spin" />
            <SparklesIcon v-else class="w-5 h-5" />
          </button>

          <button
            v-if="showMicrophoneButton"
            type="button"
            :class="[
              'h-[44px] min-w-[44px] flex items-center justify-center !rounded-xl pointer-events-auto',
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
            v-if="!isMobile || canSend || isStreaming"
            type="button"
            :disabled="!isStreaming && !canSend"
            class="h-[44px] min-w-[44px] flex items-center justify-center btn-primary !rounded-xl pointer-events-auto transition-all"
            :aria-label="isStreaming ? 'Stop' : $t('chatInput.send')"
            data-testid="btn-chat-send"
            @click="isStreaming ? emit('stop') : sendMessage()"
          >
            <div v-if="isStreaming" class="w-4 h-4 bg-white rounded-sm"></div>
            <ArrowUpIcon v-else class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Selected-model caption: tiny hint shown only when the user picked a
           specific model (the Model control now lives inside the + menu). -->
      <div
        v-if="selectedModelId !== null && selectedModelName"
        class="mt-1 text-center text-[8px] leading-none txt-muted"
        data-testid="chat-model-caption"
      >
        {{ $t('chatInput.modelCaption', { name: selectedModelName }) }}
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
import { ref, computed, watch, nextTick, onMounted, onUnmounted, type Ref } from 'vue'
import {
  ArrowUpIcon,
  XMarkIcon,
  SparklesIcon,
  MicrophoneIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import Textarea from './Textarea.vue'
import CommandPalette from './CommandPalette.vue'
import FileMentionPalette from './FileMentionPalette.vue'
import ToolsDropdown from './ToolsDropdown.vue'
import ToolBadge from './ToolBadge.vue'
import ModelDropdown from './ModelDropdown.vue'
import KnowledgeFolderPicker from './KnowledgeFolderPicker.vue'
import FileSelectionModal from './FileSelectionModal.vue'
import { parseCommand } from '../commands/parse'
import { type Command } from '@/stores/commands'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useNotification } from '@/composables/useNotification'
import { useKeyboardOpen } from '@/composables/useKeyboardOpen'
import { chatApi } from '@/services/api/chatApi'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import type { FileItem } from '@/services/filesService'
import { getFileGroups } from '@/services/filesService'
import { AudioRecorder } from '@/services/audioRecorder'
import { WebSpeechService, isWebSpeechSupported } from '@/services/webSpeechService'
import { useConfigStore } from '@/stores/config'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAutoPersist } from '@/composables/useInputPersistence'
import { useChatsStore } from '@/stores/chats'
import { useAuthStore } from '@/stores/auth'
import { useIncognitoStore } from '@/stores/incognito'
import QuoteChip from './QuoteChip.vue'
import type { QuotedReference } from '@/composables/useMessageQuoting'

interface UploadedFile {
  file_id: number
  filename: string
  file_type: string
  name?: string
  processing: boolean
}

interface Props {
  isStreaming?: boolean
  isGuestMode?: boolean
  /** Centered empty-state variant: drops the sticky-bottom positioning. */
  centered?: boolean
  quote?: QuotedReference | null
  /**
   * A banner is attached to the input's top edge via the #banner slot. On mobile
   * the banner spans the full input width, so we square off the shell's top
   * corners (max-sm only) to read as one seamless card. Rounded corners stay on
   * md+ where the banner is a compact centered pill on the flat top edge.
   */
  bannerVisible?: boolean
}

const props = defineProps<Props>()

const isStreaming = computed(() => props.isStreaming ?? false)
const isGuestMode = computed(() => props.isGuestMode ?? false)
const isCentered = computed(() => props.centered ?? false)

// Drop the home-indicator safe-area padding while the soft keyboard is open —
// the keyboard already covers that area, so the extra inset would leave a gap.
const keyboardOpen = useKeyboardOpen()

const plusMenuOpen = ref(false)
const plusMenuRef = ref<HTMLElement | null>(null)

const togglePlusMenu = () => {
  triggerHapticImpact('light')
  plusMenuOpen.value = !plusMenuOpen.value
}

const handlePlusAttach = () => {
  plusMenuOpen.value = false
  // Guests get the attach-specific hint (not the generic "files" copy).
  if (isGuestMode.value) {
    emit('guestFeatureGate', 'attach')
    return
  }
  triggerFileUpload()
}

const handlePlusGate = (key: string) => {
  plusMenuOpen.value = false
  emit('guestFeatureGate', key)
}

const handlePlusClickOutside = (e: MouseEvent) => {
  if (!plusMenuOpen.value) return
  const target = e.target as HTMLElement
  if (plusMenuRef.value && plusMenuRef.value.contains(target)) return
  plusMenuOpen.value = false
}

const message = ref('')
const originalMessage = ref('')
const enhancedMessage = ref('')
const uploadedFiles = ref<UploadedFile[]>([])
const uploading = ref(false)
const uploadAbortController = ref<AbortController | null>(null)
const enhanceEnabled = ref(false)
const enhanceLoading = ref(false)
const thinkingEnabled = ref(false)
const paletteVisible = ref(false)
const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null)
const mentionPaletteVisible = ref(false)
const mentionPaletteRef = ref<InstanceType<typeof FileMentionPalette> | null>(null)
const mentionQuery = ref('')
const textareaRef = ref<InstanceType<typeof Textarea> | null>(null)

// Tools that render as a removable badge inside the input (Cursor-style chips)
// instead of injecting a "/command" into the textarea. The message text stays
// clean and only holds the user's query; the "/command" is reconstructed at
// send time so the ChatView/backend contract is unchanged.
type ChatTool = 'search' | 'pic' | 'vid'
const TOOL_COMMANDS: readonly ChatTool[] = ['search', 'pic', 'vid'] as const
const isToolCommand = (name: string): name is ChatTool =>
  (TOOL_COMMANDS as readonly string[]).includes(name)

const activeTool = ref<ChatTool | null>(null)
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
const voiceReply = ref(false)
const discardNextRecording = ref(false)
const selectedModelId = ref<number | null>(null)
// Knowledge-base folder ("group key") to scope this chat's RAG retrieval to.
const knowledgeGroups = ref<Array<{ name: string; count: number }>>([])
const selectedGroupKey = ref<string>('')

const SILENCE_TIMEOUT_MS = 4000
const silenceTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const autoSendPending = ref(false)

const aiConfigStore = useAiConfigStore()
const chatsStore = useChatsStore()
const configStore = useConfigStore()
const authStore = useAuthStore()
const incognitoStore = useIncognitoStore()
const { warning, error: showError, success } = useNotification()
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

/**
 * Get the speech recognition language code from the current UI locale.
 * Maps short locale codes (en, de) to full BCP-47 codes (en-US, de-DE).
 */
const speechLanguage = computed(() => {
  const currentLocale = locale.value
  // Map short codes to full BCP-47 language codes for better speech recognition
  const languageMap: Record<string, string> = {
    en: 'en-US',
    de: 'de-DE',
    fr: 'fr-FR',
    es: 'es-ES',
    it: 'it-IT',
    pt: 'pt-BR',
    nl: 'nl-NL',
    pl: 'pl-PL',
    ru: 'ru-RU',
    ja: 'ja-JP',
    zh: 'zh-CN',
    ko: 'ko-KR',
  }
  return languageMap[currentLocale] || currentLocale || 'en-US'
})

/**
 * Determine if microphone button should be shown.
 * Show when: Web Speech API is supported OR any backend speech-to-text is available.
 * Backend speech-to-text includes: local Whisper.cpp OR API models (Groq/OpenAI Whisper).
 */
const showMicrophoneButton = computed(() => {
  const speechToTextAvailable = configStore.speech.speechToTextAvailable
  const webSpeechSupported = isWebSpeechSupported()

  // Show if either browser API or backend transcription is available
  return webSpeechSupported || speechToTextAvailable
})

/**
 * Icon-only enhance control inside the input shell; visible when there is
 * text to act on. Desktop only — on mobile it crowds the narrow input, so the
 * control moves into the Tools dropdown instead.
 */
const showEnhanceInInput = computed(() => message.value.trim().length > 0 && !isMobile.value)

/** Reserve horizontal space so the textarea does not sit under the absolute action buttons. */
const textareaPaddingRightPx = computed(() => {
  if (showMicrophoneButton.value) {
    return showEnhanceInInput.value ? 192 : 140
  }
  return showEnhanceInInput.value ? 120 : 80
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

// Input persistence - auto-save with proper debouncing. Disabled during an
// incognito session: drafts must never survive in localStorage.
const { clearInput: clearPersistedInput } = useAutoPersist(
  message,
  'chat',
  computed(() => chatsStore.activeChatId),
  computed(() => incognitoStore.active)
)

const emit = defineEmits<{
  send: [
    message: string,
    options?: {
      includeReasoning?: boolean
      webSearch?: boolean
      fileIds?: number[]
      voiceReply?: boolean
      modelId?: number
      ragGroupKey?: string
      quotedText?: string
      quotedMessageId?: number
    },
  ]
  stop: []
  guestFeatureGate: [featureKey: string]
  clearQuote: []
}>()

const canSend = computed(() => {
  const trimmedMessage = message.value.trim()
  const hasMessage = trimmedMessage.length > 0
  const hasFiles = uploadedFiles.value.length > 0
  const filesReady = uploadedFiles.value.every((f) => !f.processing)

  // A tool badge (search/image/video) needs a query or description to act on,
  // so an active tool with an empty textarea (and no files) can't be sent.
  if (activeTool.value && !hasMessage && !hasFiles) {
    return false
  }

  // Prevent sending if only a raw command is typed (e.g., just "/pic" without arguments)
  const isOnlyCommand = trimmedMessage.startsWith('/') && !trimmedMessage.includes(' ')
  if (isOnlyCommand && !hasFiles) {
    return false
  }

  return (hasMessage || hasFiles) && filesReady && !uploading.value
})

const currentChatModel = computed(() => {
  const chatModels = aiConfigStore.models.CHAT || []
  const resolvedModelId = selectedModelId.value ?? aiConfigStore.defaults.CHAT ?? null

  if (!resolvedModelId) {
    return null
  }

  return chatModels.find((model) => model.id === resolvedModelId) ?? null
})

// Name of the explicitly-picked model (null selection = "Default", no caption).
const selectedModelName = computed(() => {
  if (selectedModelId.value === null) return ''
  return currentChatModel.value?.name ?? ''
})

const supportsReasoning = computed(() => {
  if (!currentChatModel.value) {
    return false
  }

  return currentChatModel.value.features?.includes('reasoning') ?? false
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

// Reset model dropdown when switching chats
watch(
  () => chatsStore.activeChatId,
  () => {
    selectedModelId.value = null
  }
)

watch(
  message,
  (newValue) => {
    if (newValue.startsWith('/')) {
      // Only show palette if no space (still typing command) or only command without args
      const hasSpace = newValue.includes(' ')
      const parsed = parseCommand(newValue)

      if (parsed) {
        // Hide palette if user has started typing arguments (command + space)
        paletteVisible.value = !hasSpace || parsed.args.length === 0
      } else {
        paletteVisible.value = true
      }
    } else {
      paletteVisible.value = false
    }

    // Detect @mention trigger: match @ preceded by start-of-string or whitespace, at end of input.
    // The mention palette lists the user's knowledge-base files via the
    // auth-guarded /api/v1/files endpoint. Opening it as a guest returns 401
    // and the http client force-redirects to /login (issue #1037). Guests have
    // no knowledge-base files anyway, so we never open the palette for them and
    // let "@" stay as plain text (e.g. when typing an email address). Gate on
    // authentication rather than the isGuestMode prop, which can still be false
    // at mount while the guest session is initializing.
    const mentionMatch = newValue.match(/(?:^|\s)@(\S*)$/)
    if (mentionMatch && !paletteVisible.value && authStore.isAuthenticated) {
      mentionQuery.value = mentionMatch[1]
      mentionPaletteVisible.value = true
    } else if (!mentionMatch) {
      mentionPaletteVisible.value = false
      mentionQuery.value = ''
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
    warning(t('chatInput.waitForStreaming'))
    return
  }

  // If dictation is active, stop it FIRST and absorb any pending speech
  // (final + interim) into message.value before we evaluate canSend / send.
  // We use abort() (not stop()) on the Web Speech service so that any
  // late native onresult event cannot write transcribed text back into
  // message.value after we've cleared it below.
  if (isRecording.value) {
    const base = speechBaseMessage.value
    const finals = speechFinalTranscript.value
    const interim = interimTranscript.value
    const separator = base && (finals || interim) ? ' ' : ''
    const finalSeparator = finals && interim ? ' ' : ''
    const absorbed = base + separator + finals + finalSeparator + interim
    if (absorbed) {
      message.value = absorbed
    }

    if (webSpeechService.value) {
      webSpeechService.value.abort()
      webSpeechService.value = null
    }
    if (audioRecorder.value) {
      discardNextRecording.value = true
      audioRecorder.value.stopRecording()
    }
    isRecording.value = false
  }

  // Always clear speech tracking to prevent any late onEnd / onResult from
  // restoring text (handles race conditions around stop/abort).
  speechBaseMessage.value = ''
  speechFinalTranscript.value = ''
  interimTranscript.value = ''
  clearSilenceTimer()
  autoSendPending.value = false

  if (!canSend.value) {
    return
  }

  const hasWebSearch = activeTool.value === 'search'

  // Reconstruct the "/command query" string from the active tool badge so the
  // ChatView/backend contract stays identical to the old slash-command flow.
  // The textarea only holds the query; ChatView strips the prefix for display
  // and uses the webSearch flag for /search (see handleSendMessage).
  const query = message.value
  let messageToSend = query
  if (activeTool.value === 'pic' || activeTool.value === 'vid') {
    messageToSend = `/${activeTool.value} ${query}`.trim()
  } else if (activeTool.value === 'search') {
    messageToSend = `/search ${query}`.trim()
  }

  const options = {
    includeReasoning: thinkingEnabled.value,
    webSearch: hasWebSearch,
    fileIds: uploadedFiles.value.filter((f) => !f.processing).map((f) => f.file_id),
    voiceReply: voiceReply.value,
    modelId: selectedModelId.value || undefined,
    ragGroupKey: selectedGroupKey.value || undefined,
    quotedText: props.quote?.text || undefined,
    quotedMessageId: props.quote?.messageId || undefined,
  }
  emit('send', messageToSend, options)
  message.value = ''
  uploadedFiles.value = []
  plusMenuOpen.value = false
  paletteVisible.value = false
  mentionPaletteVisible.value = false
  mentionQuery.value = ''
  activeTool.value = null
  voiceReply.value = false
  emit('clearQuote')
  // Reset enhance state after sending
  enhanceEnabled.value = false
  originalMessage.value = ''
  enhancedMessage.value = ''
  // Clear persisted input after successful send
  clearPersistedInput()
}

const toggleThinking = () => {
  // Check if current model supports reasoning
  if (!supportsReasoning.value) {
    warning(t('chatInput.reasoningNotSupported'))
    return
  }

  thinkingEnabled.value = !thinkingEnabled.value
}

const toggleVoiceReply = () => {
  voiceReply.value = !voiceReply.value
}

// Selecting a command from the "/" autocomplete palette. The three tools
// (search/pic/vid) become a badge and strip the typed "/query" from the input;
// any other command (e.g. /tts) keeps the legacy inline-text behaviour.
const handleCommandSelect = (cmd: Command) => {
  paletteVisible.value = false
  if (isToolCommand(cmd.name)) {
    setActiveTool(cmd.name)
    // Drop the partial "/cmd" the user was typing so only the query remains.
    message.value = ''
  } else if (cmd.requiresArgs) {
    message.value = `${cmd.usage.split('[')[0].trim()} `
  } else {
    message.value = cmd.usage
  }
  // Focus textarea after command selection
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

// Picking a tool from the "+" -> Tools menu. Toggles the badge without touching
// the textarea; the user then types their query as plain text.
const handleInsertCommand = (cmd: Command) => {
  if (isToolCommand(cmd.name)) {
    setActiveTool(cmd.name)
  } else if (cmd.requiresArgs) {
    message.value = `${cmd.usage.split('[')[0].trim()} `
  } else {
    message.value = cmd.usage
  }
  // Focus textarea after selection
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

// Single active tool at a time: selecting the active tool again clears it.
const setActiveTool = (tool: ChatTool) => {
  activeTool.value = activeTool.value === tool ? null : tool
}

const closePalette = () => {
  paletteVisible.value = false
}

const clearTool = () => {
  activeTool.value = null
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (paletteVisible.value && paletteRef.value) {
    const handled = ['ArrowUp', 'ArrowDown', 'Enter', 'Escape', 'Tab']
    if (handled.includes(e.key)) {
      e.preventDefault()
      e.stopPropagation()
      paletteRef.value.handleKeyDown(e)
      return
    }
  } else if (mentionPaletteVisible.value && mentionPaletteRef.value) {
    const handled = ['ArrowUp', 'ArrowDown', 'Enter', 'Escape', 'Tab']
    if (handled.includes(e.key)) {
      e.preventDefault()
      e.stopPropagation()
      mentionPaletteRef.value.handleKeyDown(e)
      return
    }
  }

  // Backspace at the very start of an empty input removes the active tool badge
  // (Cursor-style chip deletion), so the user never has to reach for the X.
  if (e.key === 'Backspace' && activeTool.value) {
    const target = e.target as HTMLTextAreaElement
    const atStart =
      message.value.length === 0 || (target.selectionStart === 0 && target.selectionEnd === 0)
    if (atStart) {
      e.preventDefault()
      clearTool()
      return
    }
  }

  if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
    e.preventDefault()
    sendMessage()
  }
}

const removeFile = (index: number) => {
  uploadedFiles.value.splice(index, 1)
}

const triggerFileUpload = () => {
  if (props.isGuestMode) {
    emit('guestFeatureGate', 'files')
    return
  }
  if (uploading.value) return
  fileSelectionModalVisible.value = true
}

const handleFilesSelected = async (selectedFiles: FileItem[]) => {
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

const handleMentionSelect = (file: FileItem) => {
  const alreadyAttached = uploadedFiles.value.some((f) => f.file_id === file.id)
  if (!alreadyAttached) {
    uploadedFiles.value.push({
      file_id: file.id,
      filename: file.filename,
      file_type: file.file_type,
      processing: false,
    })
    success(t('fileMention.fileAttached', { name: file.filename }))
  }

  // Remove the @query text from the message
  message.value = message.value.replace(/(?:^|\s)@\S*$/, '').trimEnd()
  mentionPaletteVisible.value = false
  mentionQuery.value = ''

  nextTick(() => {
    textareaRef.value?.focus()
  })
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

// Clipboard paste handler for images and files
const handlePaste = async (event: ClipboardEvent) => {
  if (props.isGuestMode) {
    const items = event.clipboardData?.items
    if (items) {
      for (const item of items) {
        if (item.type.startsWith('image/') || item.kind === 'file') {
          event.preventDefault()
          emit('guestFeatureGate', 'files')
          return
        }
      }
    }
    return
  }

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
    } catch {
      showError(t('chatInput.uploadError'))
    }
  }
  // If no files, let the default paste behavior handle text
}

const uploadFiles = async (files: File[]) => {
  isDragging.value = false
  uploading.value = true

  if (uploadAbortController.value) {
    uploadAbortController.value.abort()
  }
  const controller = new AbortController()
  uploadAbortController.value = controller

  for (const file of files) {
    if (controller.signal.aborted) break

    const tempFile: UploadedFile = {
      file_id: 0,
      filename: file.name,
      file_type: file.name.split('.').pop() || 'unknown',
      name: file.name,
      processing: true,
    }
    uploadedFiles.value.push(tempFile)

    try {
      // Incognito sessions mark the upload ephemeral (hidden from the file
      // manager, auto-deleted after the session) and track the id for the
      // session-end cleanup.
      const result = await chatApi.uploadChatFile(file, controller.signal, {
        incognito: incognitoStore.active,
      })
      if (incognitoStore.active) {
        incognitoStore.registerFile(result.file_id)
      }

      // Issue #729: when the synchronous extraction at upload time fails
      // (corrupted DOCX, password-protected PDF, etc.), surface a clear
      // error and drop the file instead of letting the user send a
      // message that we already know will hit "extraction failed" in the
      // stream.
      if (result.extraction_error) {
        const index = uploadedFiles.value.findIndex((f) => f.name === file.name && f.processing)
        if (index !== -1) {
          uploadedFiles.value.splice(index, 1)
        }

        const errorKey =
          result.extraction_error === 'audio_transcription_failed'
            ? 'chatInput.audioTranscriptionFailed'
            : 'chatInput.documentExtractionFailed'
        showError(t(errorKey, { filename: result.filename }))
        continue
      }

      const index = uploadedFiles.value.findIndex((f) => f.name === file.name && f.processing)
      if (index !== -1) {
        uploadedFiles.value[index] = {
          file_id: result.file_id,
          filename: result.filename,
          file_type: result.file_type,
          processing: false,
        }
      }

      if (result.text) {
        const preview = result.text.substring(0, 50) + (result.text.length > 50 ? '...' : '')
        const languageInfo = result.language ? ` (${result.language})` : ''
        success(`🎙️ Audio transcribed${languageInfo}: "${preview}"`)
      }

      console.log('✅ File uploaded and processed:', result)
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        uploadedFiles.value = uploadedFiles.value.filter((f) => !f.processing)
        break
      }
      console.error('❌ File upload failed:', err)
      const errorMessage = err instanceof Error ? err.message : 'Unknown error'
      showError(`File upload failed: ${errorMessage}`)

      const index = uploadedFiles.value.findIndex((f) => f.name === file.name)
      if (index !== -1) {
        uploadedFiles.value.splice(index, 1)
      }
    }
  }

  uploading.value = false
  uploadAbortController.value = null
}

const clearSilenceTimer = () => {
  if (silenceTimer.value) {
    clearTimeout(silenceTimer.value)
    silenceTimer.value = null
  }
}

onMounted(() => {
  document.addEventListener('click', handlePlusClickOutside)
})

onUnmounted(() => {
  clearSilenceTimer()
  document.removeEventListener('click', handlePlusClickOutside)
  if (uploadAbortController.value) {
    uploadAbortController.value.abort()
    uploadAbortController.value = null
  }
})

// Load the user's knowledge-base folders so they can scope a chat to one.
// `/api/v1/files/groups` is auth-gated: calling it as a guest (or before auth
// has resolved) returns 401, which the http client turns into a hard redirect
// to /login. Gate strictly on authentication — not on the `isGuestMode` prop,
// which can still be false at mount while the guest session is initializing —
// and (re)load whenever auth state flips to authenticated.
async function loadKnowledgeGroups(): Promise<void> {
  if (!authStore.isAuthenticated) return
  try {
    knowledgeGroups.value = await getFileGroups()
    applyFolderFromQuery()
  } catch {
    // Non-fatal — the picker still renders with just the "none" option, so a
    // failed load simply means no folders are available to scope to.
  }
}

/**
 * §4.8 #2 ("Use in chat"): the Files page deep-links to `/?folder=<name>`.
 * Preselect that knowledge folder in the picker, then consume the query so
 * a reload or share of the URL doesn't re-apply it.
 */
function applyFolderFromQuery(): void {
  const folder = route.query.folder
  if (typeof folder !== 'string' || folder === '') return
  if (knowledgeGroups.value.some((g) => g.name === folder)) {
    selectedGroupKey.value = folder
  }
  const rest = { ...route.query }
  delete rest.folder
  void router.replace({ query: rest })
}

watch(
  () => authStore.isAuthenticated,
  (authed) => {
    if (authed) void loadKnowledgeGroups()
  },
  {
    immediate: true,
  }
)

// "Use in chat" while already on the chat page: the query changes without a
// remount, so apply it whenever it (re)appears.
watch(
  () => route.query.folder,
  (folder) => {
    if (typeof folder === 'string' && folder !== '' && knowledgeGroups.value.length > 0) {
      applyFolderFromQuery()
    }
  }
)

/**
 * Toggle speech recording using hybrid approach.
 * Uses Web Speech API for real-time transcription when available and whisperEnabled=false.
 * Falls back to Whisper.cpp backend recording when whisperEnabled=true.
 */
const toggleRecording = async () => {
  if (isRecording.value) {
    // Manual stop — clear auto-send so user can review the transcription
    clearSilenceTimer()
    autoSendPending.value = false

    if (webSpeechService.value) {
      webSpeechService.value.stop()
      webSpeechService.value = null
    }
    if (audioRecorder.value) {
      audioRecorder.value.stopRecording()
    }
    isRecording.value = false
    return
  }

  // Auto-enable voice reply when mic is used
  voiceReply.value = true

  // Start recording - Web Speech API has priority for real-time streaming
  if (useWebSpeech.value) {
    await startWebSpeechRecording()
  } else {
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

    webSpeechService.value = new WebSpeechService({
      language: speechLanguage.value,
      interimResults: true,
      continuous: true,
      onStart: () => {
        isRecording.value = true
        success(t('chatInput.listeningStarted'))
      },
      onEnd: () => {
        isRecording.value = false
        clearSilenceTimer()

        const base = speechBaseMessage.value
        const finals = speechFinalTranscript.value
        const interim = interimTranscript.value
        const separator = base && (finals || interim) ? ' ' : ''
        message.value = base + separator + finals + (finals && interim ? ' ' : '') + interim

        const shouldAutoSend = autoSendPending.value
        autoSendPending.value = false

        speechBaseMessage.value = ''
        speechFinalTranscript.value = ''
        interimTranscript.value = ''

        if (shouldAutoSend && message.value.trim()) {
          nextTick(() => sendMessage())
        }
      },
      onResult: ({ final, interim }) => {
        // Snapshot semantics: the service hands us the *whole* recognition
        // session so far. Assigning (never appending) the snapshot is what
        // makes the consumer immune to Android Chrome re-emitting the same
        // final segment across multiple events (issue #898).
        const base = speechBaseMessage.value
        const separator = base && (final || interim) ? ' ' : ''

        clearSilenceTimer()

        speechFinalTranscript.value = final
        interimTranscript.value = interim

        const finalInterimSeparator = final && interim ? ' ' : ''
        message.value = base + separator + final + finalInterimSeparator + interim

        if (final.trim()) {
          silenceTimer.value = setTimeout(() => {
            if (speechFinalTranscript.value.trim()) {
              autoSendPending.value = true
              webSpeechService.value?.stop()
              webSpeechService.value = null
            }
          }, SILENCE_TIMEOUT_MS)
        }
      },
      onError: (error) => {
        console.error('Web Speech error:', error)
        if (error.type !== 'no_speech') {
          showError(error.userMessage)
        }
        isRecording.value = false
      },
    })

    await webSpeechService.value.start()
  } catch (err: unknown) {
    console.error('Failed to start Web Speech:', err)
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
  if (discardNextRecording.value) {
    discardNextRecording.value = false
    return
  }

  uploading.value = true

  try {
    // Upload for transcription (WhisperCPP on backend). Incognito recordings
    // are ephemeral and tracked for the session-end cleanup.
    const result = await chatApi.transcribeAudio(audioBlob, undefined, {
      incognito: incognitoStore.active,
    })
    if (incognitoStore.active) {
      incognitoStore.registerFile(result.file_id)
    }

    if (result.text) {
      message.value += (message.value ? ' ' : '') + result.text
      nextTick(() => textareaRef.value?.focus())
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
  if (props.isGuestMode) {
    emit('guestFeatureGate', 'enhance')
    return
  }
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
  } catch (err) {
    const errorMsg = err instanceof Error ? err.message : 'Failed to enhance message'
    if (errorMsg === 'enhance_rejected') {
      showError(t('chatInput.enhanceRejected'))
    } else {
      showError(errorMsg)
    }
    console.error('Enhancement error:', err)
  } finally {
    enhanceLoading.value = false
  }
}

// Set input text programmatically (e.g., from False Positive modal)
const setInputText = (text: string) => {
  message.value = text
}

// Prefill + send in one step (e.g., landing example prompts).
const submitText = (text: string) => {
  message.value = text
  nextTick(() => sendMessage())
}

// Expose textarea ref, uploadFiles, setInputText, submitText for parent component
// ATTENTION: needs to be typed when using vue-tsc -b
defineExpose<{
  textareaRef: Ref<InstanceType<typeof Textarea> | null>
  uploadFiles: (files: File[]) => Promise<void>
  setInputText: (text: string) => void
  submitText: (text: string) => void
}>({
  textareaRef,
  uploadFiles,
  setInputText,
  submitText,
})
</script>
