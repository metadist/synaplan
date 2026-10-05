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
    :class="[
      'chat-composer-sticky bg-chat-input-area',
      { 'chat-composer-sticky--kb-open': keyboardOpen },
    ]"
    data-testid="comp-chat-input"
    @paste="handlePaste"
  >
    <!-- On mobile the horizontal padding matches the drawer toggle's left
         offset (left-3 = 12px) so the composer aligns with the menu button and
         uses the full width; md+ keeps the roomier px-4. -->
    <div class="max-w-4xl mx-auto px-3 py-2 md:px-4 md:py-4">
      <!-- File and Quote Display (above input) -->
      <div
        v-if="uploadedFiles.length > 0 || quote || pastedBlocks.length > 0"
        class="mb-3 flex flex-wrap gap-2 max-h-28 overflow-y-auto"
      >
        <!-- Quoted reference chip -->
        <QuoteChip v-if="quote" :quote="quote" @remove="emit('clearQuote')" />

        <PastedTextCard
          v-for="block in pastedBlocks"
          :key="block.id"
          :content="block.content"
          @open="openPastedBlock(block.id)"
          @remove="removePastedBlock(block.id)"
        />

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
            :aria-label="$t('files.removeFile')"
            :disabled="file.processing"
            data-testid="btn-remove-chat-file"
            @click="removeFile(index)"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div
        v-if="pendingDesktopRun"
        class="mb-3 surface-card rounded-lg p-3"
        data-testid="desktop-skill-picker"
      >
        <p v-if="pendingDesktopRun.skills.length === 0" class="text-sm txt-primary">
          {{ $t('config.desktop.run.noRunnableSkills', { name: pendingDesktopRun.deviceName }) }}
        </p>
        <p v-else class="text-sm txt-primary">
          {{ $t('config.desktop.run.pickSkill', { name: pendingDesktopRun.deviceName }) }}
        </p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="skill in pendingDesktopRun.skills"
            :key="skill"
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
            :data-testid="`btn-desktop-skill-${skill}`"
            @click="chooseDesktopSkill(skill)"
          >
            {{ skill }}
          </button>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
            data-testid="btn-desktop-skill-cancel"
            @click="pendingDesktopRun = null"
          >
            {{ $t('common.cancel') }}
          </button>
        </div>
      </div>

      <!-- DS16: waiting/failed cards for jobs dispatched to a paired computer. -->
      <div v-if="desktopJobs.length > 0" class="mb-3 flex flex-col gap-2">
        <DesktopJobCard
          v-for="job in desktopJobs"
          :key="job.id"
          :job-id="job.id"
          :device-name="job.deviceName"
          @dismiss="dismissDesktopJob(job.id)"
        />
      </div>

      <div
        v-if="summarizeArmed"
        class="mb-3 flex flex-wrap items-end gap-3"
        data-testid="summarize-options"
      >
        <div class="min-w-[8rem]">
          <label class="block text-xs font-medium txt-secondary mb-1" for="summarize-length">
            {{ $t('chatInput.tools.summarizeLength') }}
          </label>
          <select
            id="summarize-length"
            v-model="summarizeLength"
            class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-summarize-length"
          >
            <option v-for="length in summarizeLengthOptions" :key="length" :value="length">
              {{ $t(summarizeLengthOptionKey(length)) }}
            </option>
          </select>
        </div>
        <div class="min-w-[8rem]">
          <label class="block text-xs font-medium txt-secondary mb-1" for="summarize-language">
            {{ $t('chatInput.tools.summarizeLanguage') }}
          </label>
          <select
            id="summarize-language"
            v-model="summarizeLanguage"
            class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-summarize-language"
          >
            <option v-for="language in summarizeLanguageOptions" :key="language" :value="language">
              {{ $t(`chatInput.tools.summarizeLang.${language}`) }}
            </option>
          </select>
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

        <!--
          Composer body. Two rows at every size: the textarea spans the full
          width, and the control bar below holds attach, the model chip and
          the send actions. Nothing overlays the text.
        -->
        <div class="flex flex-col">
          <!-- Voice activity strip.
               Only shown on the record-then-transcribe path (see
               `showVoiceActivity`): there the transcript arrives in one piece
               when the user stops, so between tapping the microphone and
               releasing it the composer is completely inert. The Web Speech
               path writes recognised words into the textarea as they arrive
               and is its own progress indicator. -->
          <div
            v-if="showVoiceActivity"
            class="voice-activity"
            role="status"
            aria-live="polite"
            data-testid="chat-voice-activity"
          >
            <template v-if="transcribing">
              <Icon icon="mdi:loading" class="w-4 h-4 animate-spin" aria-hidden="true" />
            </template>
            <template v-else>
              <span class="voice-activity__pulse" aria-hidden="true"></span>
              <span class="voice-activity__meter" aria-hidden="true">
                <span class="voice-activity__bar"></span>
                <span class="voice-activity__bar"></span>
                <span class="voice-activity__bar"></span>
                <span class="voice-activity__bar"></span>
              </span>
            </template>
            <span>{{ voiceActivityLabel }}</span>
          </div>

          <!-- Text row. Enhance sits on this line, at the right, once there
               is text. It stays at the top when the field grows, so it keeps
               the first line instead of dropping into the control bar.
               On desktop the row is already as tall as that button
               (44px plus its 2px offset, inside the 10px vertical padding),
               so the button appearing does not push the composer up. -->
          <div class="max-h-[40vh] overflow-y-auto chat-input-scroll">
            <div
              class="flex items-start gap-1.5 px-3 py-2.5"
              :class="isMobile ? undefined : 'min-h-[66px]'"
            >
              <Textarea
                ref="textareaRef"
                v-model="message"
                :placeholder="
                  isMobile ? $t('chatInput.placeholderShort') : $t('chatInput.placeholder')
                "
                :rows="1"
                class="flex-1 min-w-0"
                data-testid="input-chat-message"
                @keydown="handleKeyDown"
                @focus="isFocused = true"
                @blur="isFocused = false"
              />

              <button
                v-if="showEnhanceInInput"
                type="button"
                :class="[
                  'mt-0.5 h-[44px] min-w-[44px] flex flex-shrink-0 items-center justify-center !rounded-xl',
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
            </div>
          </div>

          <!-- Control bar. Plus and the tool badge on the left; the model chip,
               microphone and send on the right. Enhance lives on the text row. -->
          <div
            class="flex flex-wrap items-center gap-1.5 px-3 pb-2.5"
            data-testid="section-chat-controls"
          >
            <!-- Plus menu: attach, tools and knowledge folder.
             Exempt from the guest lock rule — the menu always opens; gated items
             inside surface the guest hint popover. The model chip is in the
             control bar and gates itself. -->
            <div ref="plusMenuRef" class="relative flex-shrink-0" data-testid="section-chat-plus">
              <button
                type="button"
                :class="[
                  'surface-chip icon-ghost h-[44px] min-w-[44px] flex items-center justify-center !rounded-xl relative touch-manipulation',
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
                <!-- Same `.pill` chip style as the Model/Tools/Knowledge triggers
                 below it (previously this used the flat `.dropdown-item` row
                 style). Unlike those rows it has no secondary value to push
                 right, so `self-start` keeps it sized to its content instead
                 of stretching across the panel like the full-width rows. -->
                <button
                  type="button"
                  class="pill text-xs md:text-sm self-start"
                  data-testid="btn-plus-attach"
                  @click="handlePlusAttach"
                >
                  <Icon icon="mdi:paperclip" class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
                  <span class="font-medium">{{ $t('chatInput.plusMenu.attach') }}</span>
                </button>

                <!-- One-tap photo path: opens the OS camera directly on mobile
                 (via the `capture` input below) and an image-filtered picker on
                 desktop — no detour through the file-manager modal. -->
                <button
                  type="button"
                  class="pill text-xs md:text-sm self-start"
                  data-testid="btn-plus-photo"
                  @click="handlePlusPhoto"
                >
                  <Icon icon="mdi:camera-outline" class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
                  <span class="font-medium">{{ $t('chatInput.plusMenu.photo') }}</span>
                </button>

                <!-- Guest-mode rows: same `.pill` chip style as "Attach files"
                     above. The model chip lives in the control bar and gates
                     itself. -->
                <template v-if="isGuestMode">
                  <button
                    type="button"
                    class="pill text-xs md:text-sm"
                    data-testid="btn-plus-tools"
                    @click="handlePlusGate('tools')"
                  >
                    <Icon icon="mdi:toolbox-outline" class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
                    <span class="font-medium">{{ $t('chatInput.plusMenu.tools') }}</span>
                  </button>
                  <button
                    type="button"
                    class="pill text-xs md:text-sm"
                    data-testid="btn-plus-knowledge"
                    @click="handlePlusGate('knowledge')"
                  >
                    <Icon icon="mdi:folder-outline" class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
                    <span class="font-medium">{{ $t('chatInput.plusMenu.knowledge') }}</span>
                  </button>
                </template>

                <template v-else>
                  <ToolsDropdown
                    :active-command="activeTool"
                    :thinking-enabled="thinkingEnabled"
                    :has-reasoning-levels="reasoningLevels.length > 0"
                    :voice-reply="voiceReply"
                    :supports-reasoning="supportsReasoning"
                    :enhance-enabled="enhanceEnabled"
                    :enhance-loading="enhanceLoading"
                    :enhance-available="message.trim().length > 0"
                    @insert-command="handleInsertCommand"
                    @toggle-thinking="toggleThinking"
                    @toggle-voice-reply="toggleVoiceReply"
                    @toggle-enhance="toggleEnhance"
                    @summarize-document="armSummarize"
                    @run-on-device="handleRunOnDevice"
                  />
                  <KnowledgeFolderPicker v-model="selectedGroupKey" :groups="knowledgeGroups" />
                </template>
              </div>

              <input
                type="file"
                multiple
                class="hidden"
                accept="image/*,.heic,.heif,video/*,audio/*,.pdf,.doc,.docx,.txt,.xlsx,.xls,.pptx,.ppt,.jar"
                data-testid="input-chat-file"
                @change="handleFileSelect"
              />

              <!-- Camera capture input for the "Take photo" shortcut. `capture`
                 opens the rear camera directly on mobile; desktop browsers
                 ignore it and show an image-filtered file picker. Uploads go
                 through the same pipeline as paste/drag (no file modal). -->
              <input
                ref="cameraInputRef"
                type="file"
                class="hidden"
                accept="image/*,.heic,.heif"
                capture="environment"
                data-testid="input-chat-camera"
                @change="handleFileSelect"
              />
            </div>

            <!-- (`.tool-badge` sets display unlayered and would beat a `hidden`
                 utility, so we gate visibility with v-if, not CSS.) -->
            <ToolBadge
              v-if="activeTool"
              :tool="activeTool"
              class="min-w-0 max-w-[6.5rem] flex-shrink overflow-hidden"
              @remove="clearTool"
            />

            <div
              class="ml-auto flex min-w-0 max-w-full items-center gap-1.5"
              data-testid="section-chat-primary-actions"
            >
              <ModelDropdown
                v-model="selectedModelId"
                v-model:reasoning-effort="reasoningEffort"
                :levels="reasoningLevels"
                :guest="isGuestMode"
                @gate="emit('guestFeatureGate', 'models')"
              />

              <button
                v-if="showMicrophoneButton"
                type="button"
                :class="[
                  'h-[44px] min-w-[44px] flex items-center justify-center !rounded-xl flex-shrink-0',
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
                class="h-[44px] min-w-[44px] flex items-center justify-center btn-primary !rounded-xl flex-shrink-0 transition-all"
                :aria-label="isStreaming ? 'Stop' : $t('chatInput.send')"
                data-testid="btn-chat-send"
                @click="isStreaming ? emit('stop') : sendMessage()"
              >
                <div v-if="isStreaming" class="w-4 h-4 bg-white rounded-sm"></div>
                <ArrowUpIcon v-else class="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Selection Modal -->
    <FileSelectionModal
      :visible="fileSelectionModalVisible"
      @close="fileSelectionModalVisible = false"
      @select="handleFilesSelected"
    />

    <PastedTextModal
      :visible="editingPastedBlock !== null"
      :content="editingPastedBlock?.content ?? ''"
      @close="closePastedBlock"
      @save="savePastedBlock"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted, type Ref } from 'vue'
import { storeToRefs } from 'pinia'
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
import DesktopJobCard from './DesktopJobCard.vue'
import ModelDropdown from './ModelDropdown.vue'
import KnowledgeFolderPicker from './KnowledgeFolderPicker.vue'
import FileSelectionModal from './FileSelectionModal.vue'
import PastedTextCard from './chat/PastedTextCard.vue'
import PastedTextModal from './chat/PastedTextModal.vue'
import { parseCommand } from '../commands/parse'
import {
  initialReasoningLevel,
  modelReasoningLevels,
  reasoningSendFlags,
} from '@/utils/reasoningLevel'
import { type Command, useCommandsStore } from '@/stores/commands'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useNotification } from '@/composables/useNotification'
import { useKeyboardOpen } from '@/composables/useKeyboardOpen'
import { useSummarizeTool, type SummarizeLength } from '@/composables/useSummarizeTool'
import { chatApi } from '@/services/api/chatApi'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import { isNativeApp } from '@/services/api/nativeRuntime'
import type { FileItem } from '@/services/filesService'
import { deleteFile, getFileGroups } from '@/services/filesService'
import { AudioRecorder } from '@/services/audioRecorder'
import { speechFailureMessageKey } from '@/utils/speechFailure'
import { WebSpeechService, isWebSpeechSupported } from '@/services/webSpeechService'
import { useConfigStore } from '@/stores/config'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  useAutoPersist,
  useAttachmentPersist,
  usePastedBlocksPersist,
} from '@/composables/useInputPersistence'
import { useChatsStore } from '@/stores/chats'
import { useAuthStore } from '@/stores/auth'
import { useChatModelPickStore } from '@/stores/chatModelPick'
import { useIncognitoStore } from '@/stores/incognito'
import { useDialog } from '@/composables/useDialog'
import { desktopApi } from '@/services/api/desktopApi'
import { ApiError } from '@/services/api/httpClient'
import { isDesktopAgentEnabled } from '@/composables/useDesktopAgentFeature'
import { useDesktopDevices } from '@/composables/useDesktopDevices'
import {
  readDismissedJobIds,
  rememberDismissedJobId,
  RESTORED_JOB_LIMIT,
  shouldRestoreDesktopJob,
} from '@/utils/desktopJobCard'
import QuoteChip from './QuoteChip.vue'
import type { QuotedReference } from '@/composables/useMessageQuoting'
import {
  createPastedBlockId,
  shouldBecomeBlock,
  wrapPastedBlocks,
  type PastedTextBlock,
} from '@/utils/pastedContent'

interface UploadedFile {
  file_id: number
  filename: string
  file_type: string
  name?: string
  processing: boolean
  /** Picked in the composer, not from the Files library — DELETE on remove. */
  staged?: boolean
}

interface Props {
  isStreaming?: boolean
  isGuestMode?: boolean
  quote?: QuotedReference | null
  /**
   * A banner is attached to the input's top edge via the #banner slot. On mobile
   * the banner spans the full input width, so we square off the shell's top
   * corners (max-sm only) to read as one seamless card. Rounded corners stay on
   * md+ where the banner is a compact centered pill on the flat top edge.
   */
  bannerVisible?: boolean
  /**
   * The thread this message would land in is still being chosen (for example
   * Start chat is opening an empty chat for the assistant). Keep Send off
   * until that finishes so the message is not written into the previous chat.
   */
  sendLocked?: boolean
}

const props = defineProps<Props>()

const isStreaming = computed(() => props.isStreaming ?? false)
const isGuestMode = computed(() => props.isGuestMode ?? false)

// Drop the home-indicator safe-area padding while the soft keyboard is open —
// the keyboard already covers that area, so the extra inset would leave a gap.
const keyboardOpen = useKeyboardOpen()

const plusMenuOpen = ref(false)
const plusMenuRef = ref<HTMLElement | null>(null)
const cameraInputRef = ref<HTMLInputElement | null>(null)

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

const handlePlusPhoto = () => {
  plusMenuOpen.value = false
  if (isGuestMode.value) {
    emit('guestFeatureGate', 'attach')
    return
  }
  if (uploading.value) return
  cameraInputRef.value?.click()
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

const handlePlusEscape = (e: KeyboardEvent) => {
  if (!plusMenuOpen.value || e.key !== 'Escape') return
  e.preventDefault()
  plusMenuOpen.value = false
}

const message = ref('')
const originalMessage = ref('')
const enhancedMessage = ref('')
const uploadedFiles = ref<UploadedFile[]>([])
const pastedBlocks = ref<PastedTextBlock[]>([])
const editingPastedBlockId = ref<string | null>(null)
const uploading = ref(false)
const uploadAbortController = ref<AbortController | null>(null)
const enhanceEnabled = ref(false)
const enhanceLoading = ref(false)
const thinkingEnabled = ref(false)
const reasoningEffort = ref('')
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
const transcribing = ref(false)
const audioRecorder = ref<AudioRecorder | null>(null)
const webSpeechService = ref<WebSpeechService | null>(null)
const interimTranscript = ref('')
const speechBaseMessage = ref('') // Message content before recording started
const speechFinalTranscript = ref('') // Accumulated final transcripts during recording
const fileSelectionModalVisible = ref(false)
const voiceReply = ref(false)
const discardNextRecording = ref(false)
/** Set on unmount so a late recognition or recorder callback cannot write state or upload audio. */
let dictationUnmounted = false
// Knowledge-base folder ("group key") to scope this chat's RAG retrieval to.
const knowledgeGroups = ref<Array<{ name: string; count: number }>>([])
const selectedGroupKey = ref<string>('')

const SILENCE_TIMEOUT_MS = 4000
const silenceTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const autoSendPending = ref(false)

const aiConfigStore = useAiConfigStore()
const chatsStore = useChatsStore()
const chatModelPick = useChatModelPickStore()
const { selectedModelId } = storeToRefs(chatModelPick)
const configStore = useConfigStore()
const authStore = useAuthStore()
const commandsStore = useCommandsStore()
const incognitoStore = useIncognitoStore()
const dialog = useDialog()
const { warning, error: showError, success } = useNotification()

// DS16: jobs dispatched to a paired computer via "Run on this computer".
// Rendered as waiting/failed cards above the composer until dismissed.
// Restored from the job list so a reload mid-wait does not hide them.
const desktopJobs = ref<Array<{ id: number; deviceName: string; chatId: number | null }>>([])
const pendingDesktopRun = ref<{
  deviceId: number
  deviceName: string
  skills: string[]
  prompt: string
  chatId: number | null
} | null>(null)
const {
  devices,
  ensureLoaded: ensureDesktopDevices,
  reload: reloadDesktopDevices,
} = useDesktopDevices()
let desktopRestoreSeq = 0

/**
 * Send the typed instruction to a paired computer as a `skill.run` job. There is
 * deliberately no planner hook (prompt-injection risk, §2.3): the user picks the
 * skill explicitly. The server never verifies the skill exists — an uninstalled
 * skill fails honestly on the device, surfaced by the waiting/failed card.
 */
const handleRunOnDevice = async (device: {
  id: number
  name: string
  enabledSkills?: string[]
}) => {
  plusMenuOpen.value = false
  const promptText = message.value.trim()
  if (!promptText) {
    warning(t('config.desktop.run.needPrompt'))
    return
  }
  const chatId = chatsStore.activeChatId

  await reloadDesktopDevices()
  if (chatsStore.activeChatId !== chatId) return

  const fresh = devices.value.find((row) => row.id === device.id && row.status === 'active')
  if (!fresh) {
    showError(t('config.desktop.run.inactive', { name: device.name }))
    return
  }

  const skills = reportedSkills(fresh.enabledSkills ?? device.enabledSkills)
  if (fresh.skillsReported && skills.length === 0) {
    pendingDesktopRun.value = {
      deviceId: fresh.id,
      deviceName: fresh.name,
      skills: [],
      prompt: promptText,
      chatId,
    }
    return
  }
  if (skills.length > 0) {
    pendingDesktopRun.value = {
      deviceId: fresh.id,
      deviceName: fresh.name,
      skills,
      prompt: promptText,
      chatId,
    }
    return
  }

  const skill = (
    await dialog.prompt({
      title: t('config.desktop.run.skillTitle'),
      message: t('config.desktop.run.noSkills', { name: fresh.name }),
      placeholder: t('config.desktop.run.skillPlaceholder'),
      confirmText: t('config.desktop.run.action'),
      cancelText: t('common.cancel'),
    })
  )?.trim()
  if (!skill || chatsStore.activeChatId !== chatId) return

  if (!/^[a-z0-9-]{1,64}$/.test(skill)) {
    showError(t('config.desktop.run.invalidSkill'))
    return
  }

  await sendDesktopRun(fresh.id, fresh.name, skill, promptText, chatId)
}

const reportedSkills = (skills: string[] | undefined): string[] =>
  (skills ?? []).filter((skill) => /^[a-z0-9-]{1,64}$/.test(skill))

const chooseDesktopSkill = (skill: string) => {
  const pending = pendingDesktopRun.value
  if (!pending) return
  pendingDesktopRun.value = null
  void sendDesktopRun(pending.deviceId, pending.deviceName, skill, pending.prompt, pending.chatId)
}

const sendDesktopRun = async (
  deviceId: number,
  deviceName: string,
  skill: string,
  prompt: string,
  chatId: number | null
) => {
  try {
    const { jobId, chatTitle } = await desktopApi.enqueueJob({
      deviceId,
      skill,
      prompt,
      chatId,
    })
    if (chatId && chatTitle) chatsStore.applyChatTitle(chatId, chatTitle)
    desktopJobs.value.push({ id: jobId, deviceName, chatId })
    if (message.value.trim() === prompt) message.value = ''
    pendingDesktopRun.value = null
    success(t('config.desktop.run.sent', { name: deviceName }))
  } catch (err) {
    if (err instanceof ApiError && err.code === 'device_inactive') {
      showError(t('config.desktop.run.inactive', { name: deviceName }))
      return
    }
    showError(t('config.desktop.run.enqueueFailed', { name: deviceName }))
  }
}

const desktopDeviceName = (deviceId: number | null | undefined): string => {
  if (deviceId == null) return t('config.desktop.jobCard.thisComputer')
  return (
    devices.value.find((device) => device.id === deviceId)?.name ||
    t('config.desktop.jobCard.thisComputer')
  )
}

const restoreDesktopJobs = async () => {
  const chatId = chatsStore.activeChatId
  const seq = ++desktopRestoreSeq
  if (!chatId || !isDesktopAgentEnabled()) {
    if (seq === desktopRestoreSeq) desktopJobs.value = []
    return
  }
  try {
    await ensureDesktopDevices()
    const jobs = (await desktopApi.listJobs()) ?? []
    if (seq !== desktopRestoreSeq || chatsStore.activeChatId !== chatId) return
    const dismissed = readDismissedJobIds(localStorage)
    const now = Date.now()
    const restored: Array<{ id: number; deviceName: string; chatId: number | null }> = []
    for (const job of jobs) {
      if (!shouldRestoreDesktopJob(job, chatId, dismissed, now)) continue
      restored.push({
        id: job.id,
        chatId,
        deviceName: desktopDeviceName(job.deviceId),
      })
      if (restored.length >= RESTORED_JOB_LIMIT) break
    }
    const seen = new Set(restored.map((job) => job.id))
    for (const extra of desktopJobs.value) {
      if (extra.chatId === chatId && !seen.has(extra.id) && !dismissed.has(extra.id)) {
        restored.unshift(extra)
      }
    }
    desktopJobs.value = restored
  } catch {
    // A failed reload must not wipe a card the person just started.
  }
}

const dismissDesktopJob = (jobId: number) => {
  try {
    rememberDismissedJobId(localStorage, jobId)
  } catch {
    // Dismiss still hides the card for this visit when storage is blocked.
  }
  desktopJobs.value = desktopJobs.value.filter((job) => job.id !== jobId)
}

watch(
  () => chatsStore.activeChatId,
  () => {
    pendingDesktopRun.value = null
    void restoreDesktopJobs()
  }
)

onMounted(() => {
  void restoreDesktopJobs()
})
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const {
  buildSummarizeInstruction,
  defaultLanguage,
  languageOptions: summarizeLanguageOptions,
  lengthOptions: summarizeLengthOptions,
} = useSummarizeTool()

const summarizeArmed = ref(false)
const summarizeLength = ref<SummarizeLength>('medium')
const summarizeLanguage = ref(defaultLanguage())
const lastSummarizeInstruction = ref('')

const summarizeLengthOptionKey = (length: SummarizeLength): string =>
  `chatInput.tools.summarizeLength${length.charAt(0).toUpperCase()}${length.slice(1)}`

const applySummarizeInstruction = () => {
  const instruction = buildSummarizeInstruction({
    length: summarizeLength.value,
    language: summarizeLanguage.value,
  })
  message.value = instruction
  lastSummarizeInstruction.value = instruction
}

const prefillSummarizeInstructionIfEmpty = () => {
  if (!summarizeArmed.value) {
    return
  }
  if (message.value.trim() && message.value !== lastSummarizeInstruction.value) {
    return
  }
  applySummarizeInstruction()
}

const disarmSummarize = (options?: { clearPrefill?: boolean }) => {
  if (options?.clearPrefill && message.value === lastSummarizeInstruction.value) {
    message.value = ''
  }
  summarizeArmed.value = false
  lastSummarizeInstruction.value = ''
}

const armSummarize = () => {
  if (isGuestMode.value) {
    emit('guestFeatureGate', 'attach')
    return
  }
  summarizeArmed.value = true
  if (uploadedFiles.value.length > 0) {
    prefillSummarizeInstructionIfEmpty()
    return
  }
  handlePlusAttach()
}

watch(
  () => uploadedFiles.value.length,
  (count, prev) => {
    if (!summarizeArmed.value) {
      return
    }
    if (count === 0 && (prev ?? 0) > 0) {
      disarmSummarize({ clearPrefill: true })
      return
    }
    if (count > 0) {
      prefillSummarizeInstructionIfEmpty()
    }
  }
)

watch([summarizeLength, summarizeLanguage], () => {
  if (!summarizeArmed.value || uploadedFiles.value.length === 0) {
    return
  }
  if (!message.value.trim() || message.value === lastSummarizeInstruction.value) {
    applySummarizeInstruction()
  }
})

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
    tr: 'tr-TR',
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
 * Show when: Web Speech API is usable (supported and not vetoed by
 * WEB_SPEECH_ENABLED) OR any backend speech-to-text is available.
 * Backend speech-to-text includes: local Whisper.cpp OR API models (Groq/OpenAI Whisper).
 *
 * MOBILE-APP SEAM: in the app the Web Speech API does not count (see
 * `useWebSpeech`), so the button only appears when the server can transcribe.
 * Offering it without a working path is what produced the review rejection.
 */
const showMicrophoneButton = computed(() => {
  const speechToTextAvailable = configStore.speech.speechToTextAvailable

  // Show if either browser API (when the deployment allows it) or backend
  // transcription is available
  return useWebSpeech.value || speechToTextAvailable
})

/**
 * Icon-only enhance control on the text row, at the right. Visible when
 * there is text to act on. Desktop only — on a phone it stays in the Tools
 * menu so the narrow text line keeps its width.
 */
const showEnhanceInInput = computed(() => message.value.trim().length > 0 && !isMobile.value)

/**
 * Determine which speech recognition method to use.
 * Priority: Web Speech API FIRST (real-time streaming), Whisper as fallback.
 *
 * - Web Speech API: Real-time streaming, works in Chrome/Edge/Safari. Streams
 *   the audio to the browser vendor's cloud, so deployments can veto it with
 *   WEB_SPEECH_ENABLED=false (air-gapped / data residency); Chromium builds
 *   without Google API keys also advertise it without ever returning text.
 * - Whisper backend: Record-then-transcribe, works everywhere
 *
 * MOBILE-APP SEAM: never in the app. `isWebSpeechSupported()` only checks that
 * the constructor exists, and iOS WKWebView exposes `webkitSpeechRecognition`
 * without a working recognition service behind it. The request fails with
 * `not-allowed` before the system ever asks for the microphone, so the user
 * sees a permission error for a permission that was never requested. Going
 * straight to the recorder keeps `getUserMedia` in charge, which is what
 * triggers the real iOS/Android permission prompt.
 */
const useWebSpeech = computed(() => {
  return isWebSpeechSupported() && !isNativeApp() && configStore.speech.webSpeechEnabled
})

/**
 * Whether to show the animated "recording / transcribing" strip.
 *
 * Scoped to the record-then-transcribe path, which is what the native app
 * always uses (see the seam on `useWebSpeech`) and what any browser without
 * the Web Speech API falls back to. On that path no text reaches the textarea
 * until the upload completes, so a live microphone is otherwise indistinguishable
 * from a dead one. The Web Speech path already streams words in as it hears them.
 */
const showVoiceActivity = computed(
  () => !useWebSpeech.value && (isRecording.value || transcribing.value)
)

const voiceActivityLabel = computed(() =>
  transcribing.value
    ? t('chatInput.voiceStatus.transcribing')
    : t('chatInput.voiceStatus.recording')
)

// Input persistence - auto-save with proper debouncing. Disabled during an
// incognito session: drafts must never survive in localStorage.
const { clearInput: clearPersistedInput } = useAutoPersist(
  message,
  'chat',
  computed(() => chatsStore.activeChatId),
  computed(() => incognitoStore.active)
)

// #1345: also persist already-uploaded attachments per chat (file_id + meta).
const { clearAttachments: clearPersistedAttachments } = useAttachmentPersist(
  uploadedFiles,
  'chat',
  computed(() => chatsStore.activeChatId),
  computed(() => incognitoStore.active)
)

const { clearPastedBlocks: clearPersistedBlocks } = usePastedBlocksPersist(
  pastedBlocks,
  'chat',
  computed(() => chatsStore.activeChatId),
  computed(() => incognitoStore.active)
)

const editingPastedBlock = computed(
  () => pastedBlocks.value.find((block) => block.id === editingPastedBlockId.value) ?? null
)

const emit = defineEmits<{
  send: [
    message: string,
    options?: {
      includeReasoning?: boolean
      reasoningEffort?: string
      webSearch?: boolean
      fileIds?: number[]
      voiceReply?: boolean
      modelId?: number
      ragGroupKey?: string
      quotedText?: string
      quotedMessageId?: number
      language?: string
    },
  ]
  stop: []
  guestFeatureGate: [featureKey: string]
  clearQuote: []
}>()

const canSend = computed(() => {
  if (props.sendLocked) {
    return false
  }
  const trimmedMessage = message.value.trim()
  const hasMessage = trimmedMessage.length > 0
  const hasFiles = uploadedFiles.value.length > 0
  const hasPastedBlocks = pastedBlocks.value.length > 0
  const filesReady = uploadedFiles.value.every((f) => !f.processing)
  const readyFileCount = uploadedFiles.value.filter((f) => !f.processing).length

  if (summarizeArmed.value && readyFileCount === 0) {
    return false
  }

  // A tool badge (search/image/video) needs a query or description to act on,
  // so an active tool with an empty textarea (and no files) can't be sent.
  if (activeTool.value && !hasMessage && !hasFiles && !hasPastedBlocks) {
    return false
  }

  // Prevent sending if only a raw command is typed (e.g., just "/pic" without arguments).
  // Commands that take no arguments (e.g. /help) are sendable as-is.
  const isOnlyCommand = trimmedMessage.startsWith('/') && !trimmedMessage.includes(' ')
  if (isOnlyCommand && !hasFiles && !hasPastedBlocks) {
    const cmd = commandsStore.getCommand(trimmedMessage.slice(1))
    if (!cmd || cmd.requiresArgs) {
      return false
    }
  }

  return (hasMessage || hasFiles || hasPastedBlocks) && filesReady && !uploading.value
})

const currentChatModel = computed(() => {
  const chatModels = aiConfigStore.models.CHAT || []
  const resolvedModelId = selectedModelId.value ?? aiConfigStore.defaults.CHAT ?? null

  if (!resolvedModelId) {
    return null
  }

  return chatModels.find((model) => model.id === resolvedModelId) ?? null
})

const supportsReasoning = computed(() => {
  if (!currentChatModel.value) {
    return false
  }

  return currentChatModel.value.features?.includes('reasoning') ?? false
})

const reasoningLevels = computed(() => modelReasoningLevels(currentChatModel.value))

watch(
  () => {
    const model = currentChatModel.value
    const levels = modelReasoningLevels(model)
    return `${model?.id ?? ''}:${levels.join(',')}:${model?.reasoningEffortDefault ?? ''}`
  },
  () => {
    reasoningEffort.value = initialReasoningLevel(
      reasoningLevels.value,
      currentChatModel.value?.reasoningEffortDefault
    )
  },
  { immediate: true }
)

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

// Reset model dropdown when switching chats. The same clear runs after a
// successful model-mix apply, so both paths drop the explicit pick.
watch(
  () => chatsStore.activeChatId,
  () => {
    chatModelPick.clear()
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

/**
 * Stop Web Speech and the audio recorder.
 *
 * `keepText` absorbs the live transcript into the composer (send). Leaving
 * it false discards the recording so nothing is uploaded (unmount).
 */
const stopDictation = (options: { keepText: boolean }) => {
  const recording = isRecording.value
  if (options.keepText && recording) {
    const base = speechBaseMessage.value
    const finals = speechFinalTranscript.value
    const interim = interimTranscript.value
    const separator = base && (finals || interim) ? ' ' : ''
    const finalSeparator = finals && interim ? ' ' : ''
    const absorbed = base + separator + finals + finalSeparator + interim
    if (absorbed) {
      message.value = absorbed
    }
  }

  if (webSpeechService.value) {
    webSpeechService.value.abort()
    webSpeechService.value = null
  }
  if (audioRecorder.value && (recording || !options.keepText)) {
    discardNextRecording.value = true
    audioRecorder.value.stopRecording()
  }
  if (recording || !options.keepText) {
    isRecording.value = false
  }

  speechBaseMessage.value = ''
  speechFinalTranscript.value = ''
  interimTranscript.value = ''
  clearSilenceTimer()
  autoSendPending.value = false
}

const sendMessage = () => {
  if (isStreaming.value) {
    warning(t('chatInput.waitForStreaming'))
    return
  }

  // Absorb any pending speech, then abort recognition so a late onresult
  // cannot write text back after the composer is cleared for the next turn.
  stopDictation({ keepText: true })

  if (!canSend.value) {
    return
  }

  const hasWebSearch = activeTool.value === 'search'

  // Reconstruct the "/command query" string from the active tool badge so the
  // ChatView/backend contract stays identical to the old slash-command flow.
  // The textarea only holds the query; ChatView strips the prefix for display
  // and uses the webSearch flag for /search (see handleSendMessage).
  const query = wrapPastedBlocks(message.value, pastedBlocks.value)
  let messageToSend = query
  if (activeTool.value === 'pic' || activeTool.value === 'vid') {
    messageToSend = `/${activeTool.value} ${query}`.trim()
  } else if (activeTool.value === 'search') {
    messageToSend = `/search ${query}`.trim()
  }

  const reasoning = reasoningSendFlags(
    reasoningLevels.value,
    reasoningEffort.value,
    thinkingEnabled.value
  )
  const options = {
    includeReasoning: reasoning.includeReasoning,
    ...(reasoning.reasoningEffort ? { reasoningEffort: reasoning.reasoningEffort } : {}),
    webSearch: hasWebSearch,
    fileIds: uploadedFiles.value.filter((f) => !f.processing).map((f) => f.file_id),
    voiceReply: voiceReply.value,
    modelId: selectedModelId.value || undefined,
    ragGroupKey: selectedGroupKey.value || undefined,
    quotedText: props.quote?.text || undefined,
    quotedMessageId: props.quote?.messageId || undefined,
    ...(summarizeArmed.value ? { language: summarizeLanguage.value } : {}),
  }
  emit('send', messageToSend, options)
  disarmSummarize()
  message.value = ''
  uploadedFiles.value = []
  pastedBlocks.value = []
  editingPastedBlockId.value = null
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
  clearPersistedAttachments()
  clearPersistedBlocks()

  // Sending via the button moves focus onto that button, so the next message
  // would need a click back into the composer. Refocus synchronously — inside
  // the click gesture — so the mobile keyboard stays open as well.
  textareaRef.value?.focus()
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
  const file = uploadedFiles.value[index]
  if (!file) return
  uploadedFiles.value.splice(index, 1)
  if (file.staged && file.file_id > 0) {
    void deleteFile(file.file_id).catch(() => {})
  }
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

const attachExistingFile = (file: { file_id: number; filename: string; file_type: string }) => {
  if (uploadedFiles.value.some((f) => f.file_id === file.file_id)) {
    return
  }
  uploadedFiles.value.push({
    file_id: file.file_id,
    filename: file.filename,
    file_type: file.file_type,
    processing: false,
  })
  success(t('chat.conversationFiles.attached', { name: file.filename }))
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

const isComposerTextarea = (target: EventTarget | null): boolean => {
  if (!(target instanceof HTMLElement)) {
    return false
  }
  if (target instanceof HTMLTextAreaElement && target.closest('[data-testid="comp-chat-input"]')) {
    return true
  }
  return Boolean(
    target.closest('[data-testid="input-chat-message"], [data-testid="input-textarea"]')
  )
}

const addPastedBlock = (content: string) => {
  pastedBlocks.value.push({
    id: createPastedBlockId(),
    content,
  })
  triggerHapticImpact('light')
}

const removePastedBlock = (id: string) => {
  pastedBlocks.value = pastedBlocks.value.filter((block) => block.id !== id)
  if (editingPastedBlockId.value === id) {
    editingPastedBlockId.value = null
  }
  triggerHapticImpact('light')
}

const openPastedBlock = (id: string) => {
  editingPastedBlockId.value = id
}

const closePastedBlock = () => {
  editingPastedBlockId.value = null
  if (typeof window !== 'undefined' && window.matchMedia('(min-width: 640px)').matches) {
    textareaRef.value?.focus()
  }
}

const savePastedBlock = (content: string) => {
  const id = editingPastedBlockId.value
  if (!id) {
    return
  }
  if (!content.trim()) {
    removePastedBlock(id)
    closePastedBlock()
    return
  }
  pastedBlocks.value = pastedBlocks.value.map((block) =>
    block.id === id ? { ...block, content } : block
  )
  closePastedBlock()
}

// Clipboard paste handler for images, files, and large text
const handlePaste = async (event: ClipboardEvent) => {
  const items = event.clipboardData?.items

  if (items) {
    const filesToUpload: File[] = []

    for (const item of items) {
      if (item.type.startsWith('image/')) {
        const file = item.getAsFile()
        if (file) {
          const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5)
          const extension = item.type.split('/')[1] || 'png'
          const namedFile = new File([file], `pasted-image-${timestamp}.${extension}`, {
            type: file.type,
          })
          filesToUpload.push(namedFile)
        }
      } else if (item.kind === 'file') {
        const file = item.getAsFile()
        if (file) {
          filesToUpload.push(file)
        }
      }
    }

    if (filesToUpload.length > 0) {
      event.preventDefault()
      if (props.isGuestMode) {
        emit('guestFeatureGate', 'files')
        return
      }
      try {
        await uploadFiles(filesToUpload)
        success(t('chatInput.filesPasted', { count: filesToUpload.length }))
      } catch {
        showError(t('chatInput.uploadError'))
      }
      return
    }
  }

  const pastedText = event.clipboardData?.getData('text/plain') ?? ''
  if (!pastedText || !shouldBecomeBlock(pastedText)) {
    return
  }

  if (!isComposerTextarea(event.target) && !isComposerTextarea(document.activeElement)) {
    return
  }

  event.preventDefault()
  addPastedBlock(pastedText)
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
      staged: true,
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
        if (result.file_id) {
          void deleteFile(result.file_id).catch(() => {})
        }

        if (result.extraction_error === 'audio_transcription_failed') {
          const failureKey = speechFailureMessageKey(result.speech_failure, 'file')
          showError(
            failureKey === 'chatInput.audioTranscriptionFailed'
              ? t(failureKey, { filename: result.filename })
              : t(failureKey)
          )
        } else {
          showError(t('chatInput.documentExtractionFailed', { filename: result.filename }))
        }
        continue
      }

      const index = uploadedFiles.value.findIndex((f) => f.name === file.name && f.processing)
      if (index !== -1) {
        uploadedFiles.value[index] = {
          file_id: result.file_id,
          filename: result.filename,
          file_type: result.file_type,
          processing: false,
          staged: true,
        }
      }

      if (result.text) {
        const preview = result.text.substring(0, 50) + (result.text.length > 50 ? '...' : '')
        const languageInfo = result.language ? ` (${result.language})` : ''
        success(t('chatInput.transcribed', { language: languageInfo, preview }))
      }

      console.log('✅ File uploaded and processed:', result)
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        uploadedFiles.value = uploadedFiles.value.filter((f) => !f.processing)
        break
      }
      console.error('❌ File upload failed:', err)
      showError(t('chatInput.uploadError'))

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
  document.addEventListener('keydown', handlePlusEscape)
})

onUnmounted(() => {
  dictationUnmounted = true
  stopDictation({ keepText: false })
  document.removeEventListener('click', handlePlusClickOutside)
  document.removeEventListener('keydown', handlePlusEscape)
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
  const isSharedPicker = folder.startsWith('shared:')
  if (isSharedPicker || knowledgeGroups.value.some((g) => g.name === folder)) {
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
    if (typeof folder === 'string' && folder !== '') {
      applyFolderFromQuery()
    }
  }
)

/**
 * Toggle speech recording using hybrid approach.
 * Uses Web Speech API for real-time transcription when the browser has it and
 * the deployment allows it (see `useWebSpeech`), otherwise records for the
 * server-side transcription path.
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
      // Do NOT clear `isRecording` here: MediaRecorder's onstop event fires
      // asynchronously, so a synchronous clear would unmount the activity
      // strip for a frame before `transcribeAudio` sets `transcribing`. The
      // recorder's onStop/onError callbacks and transcribeAudio's `finally`
      // own the flag from this point on.
      audioRecorder.value.stopRecording()
    } else {
      isRecording.value = false
    }
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
        if (dictationUnmounted) return
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
        if (dictationUnmounted) return
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
        if (dictationUnmounted) return
        console.error('Web Speech error:', error)
        if (error.type !== 'no_speech') {
          showError(error.userMessage)
        }
        isRecording.value = false
      },
    })

    const service = webSpeechService.value
    await service.start()
    if (dictationUnmounted) {
      service.abort()
      webSpeechService.value = null
    }
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
    const recorder = new AudioRecorder({
      onStart: () => {
        if (dictationUnmounted) return
        isRecording.value = true
        success(t('chatInput.recordingStarted'))
      },
      onStop: () => {
        if (dictationUnmounted) return
        isRecording.value = false
      },
      onDataAvailable: async (audioBlob: Blob) => {
        if (dictationUnmounted) return
        console.log('🎵 Audio recorded:', audioBlob.size, 'bytes')
        await transcribeAudio(audioBlob)
      },
      onError: (error) => {
        if (dictationUnmounted) return
        console.error('❌ Recording error:', error)
        showError(t(error.messageKey))
        isRecording.value = false
      },
    })
    audioRecorder.value = recorder

    // Check support first (with detailed diagnostics). Unmount can happen
    // while permission is pending; stop again after each await so a stream
    // created when getUserMedia later resolves is not left open.
    const support = await recorder.checkSupport()
    if (dictationUnmounted) {
      recorder.stopRecording()
      return
    }
    if (!support.supported || !support.hasDevices) {
      if (support.error) {
        showError(t(support.error.messageKey))
      }
      return
    }

    await recorder.startRecording()
    if (dictationUnmounted) {
      recorder.stopRecording()
    }
  } catch (err: unknown) {
    console.error('❌ Failed to start recording:', err)
    const error = err as { messageKey?: string; message?: string }
    showError(
      error.messageKey
        ? t(error.messageKey)
        : t('chatInput.recordingError', { error: error.message || 'Unknown error' })
    )
    isRecording.value = false
  }
}

/**
 * Transcribe audio blob using Whisper.cpp backend.
 * Called after AudioRecorder stops and provides recorded audio.
 */
const transcribeAudio = async (audioBlob: Blob) => {
  if (dictationUnmounted || discardNextRecording.value) {
    discardNextRecording.value = false
    return
  }

  // Set before the first await so the activity strip switches straight from
  // "recording" to "transcribing". This only avoids a blink because the stop
  // tap in `toggleRecording` deliberately leaves `isRecording` true until the
  // recorder's callbacks run, and AudioRecorder invokes this callback before
  // its own onStop — so the strip is still mounted when `transcribing` is set.
  transcribing.value = true
  uploading.value = true

  try {
    // Upload for transcription (WhisperCPP on backend). Incognito recordings
    // are ephemeral and tracked for the session-end cleanup.
    const result = await chatApi.transcribeAudio(audioBlob, undefined, {
      incognito: incognitoStore.active,
    })
    if (incognitoStore.active && result.file_id) {
      incognitoStore.registerFile(result.file_id)
    }

    if (result.extraction_error === 'audio_transcription_failed') {
      // Same upload-file endpoint as attachments: empty text here means STT
      // is missing or failed, not "no speech" (issue #1908). The code names
      // the recovery; the provider's text is not shown.
      showError(t(speechFailureMessageKey(result.speech_failure, 'dictation')))
    } else if (result.text) {
      message.value += (message.value ? ' ' : '') + result.text
      nextTick(() => textareaRef.value?.focus())
    } else {
      warning(t('chatInput.noSpeechDetected'))
    }
  } catch (err: unknown) {
    console.error('❌ Transcription failed:', err)
    showError(t('chatInput.dictationSttFailed'))
  } finally {
    isRecording.value = false
    transcribing.value = false
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

// Prefill + send in one step (e.g., landing example prompts). Resolves false
// when the composer refused the send; the text then stays in the box.
const submitText = async (text: string): Promise<boolean> => {
  message.value = text
  await nextTick()
  const sendable = !isStreaming.value && canSend.value
  sendMessage()
  return sendable
}

/**
 * MOBILE-APP SEAM: start voice dictation for the iOS Shortcuts "Start
 * dictation" action. No-op when already recording. Returns false (and a
 * toast) when this server has no speech-to-text path.
 */
const startDictation = async (): Promise<boolean> => {
  if (isRecording.value) {
    return true
  }
  if (!showMicrophoneButton.value) {
    showError(t('chatInput.dictationUnavailable'))
    return false
  }
  await toggleRecording()
  return isRecording.value
}

// Expose textarea ref, uploadFiles, setInputText, submitText for parent component
// ATTENTION: needs to be typed when using vue-tsc -b
defineExpose<{
  textareaRef: Ref<InstanceType<typeof Textarea> | null>
  uploadFiles: (files: File[]) => Promise<void>
  attachExistingFile: (file: { file_id: number; filename: string; file_type: string }) => void
  setInputText: (text: string) => void
  submitText: (text: string) => Promise<boolean>
  startDictation: () => Promise<boolean>
  armSummarize: () => void
}>({
  textareaRef,
  uploadFiles,
  attachExistingFile,
  setInputText,
  submitText,
  startDictation,
  armSummarize,
})
</script>

<style scoped>
/*
 * Voice activity strip for the record-then-transcribe path.
 *
 * Three cues at once so the state reads at a glance: a pulsing red dot (the
 * universal "we are recording" mark), a four-bar level meter that keeps moving
 * so a live microphone never looks frozen, and the label. Colors come from the
 * shared status tokens, which covers light, dark and the V2 glass design
 * without a second definition. Follows the `.typing-dot` pattern in
 * ChatMessage.vue.
 */
.voice-activity {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px 0;
  font-size: 12px;
  line-height: 1;
  color: var(--txt-secondary);
}

.voice-activity__pulse {
  width: 8px;
  height: 8px;
  flex-shrink: 0;
  border-radius: 9999px;
  background: var(--status-error);
  animation: voice-pulse 1.4s ease-in-out infinite;
}

.voice-activity__meter {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  height: 14px;
}

.voice-activity__bar {
  width: 3px;
  height: 100%;
  border-radius: 9999px;
  background: var(--brand);
  animation: voice-level 1s ease-in-out infinite;
}

/* Staggered so the meter sweeps left-to-right instead of pumping in unison. */
.voice-activity__bar:nth-child(1) {
  animation-delay: -0.9s;
}
.voice-activity__bar:nth-child(2) {
  animation-delay: -0.6s;
}
.voice-activity__bar:nth-child(3) {
  animation-delay: -0.3s;
}

@keyframes voice-pulse {
  0%,
  100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.35;
    transform: scale(0.7);
  }
}

@keyframes voice-level {
  0%,
  100% {
    transform: scaleY(0.3);
    opacity: 0.5;
  }
  50% {
    transform: scaleY(1);
    opacity: 1;
  }
}

/* The motion is decoration — the label and the live region carry the meaning. */
@media (prefers-reduced-motion: reduce) {
  .voice-activity__pulse,
  .voice-activity__bar {
    animation: none;
  }
  .voice-activity__bar {
    transform: scaleY(0.7);
  }
}
</style>
