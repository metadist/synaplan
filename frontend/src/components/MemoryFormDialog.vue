<template>
  <Teleport :to="teleportTarget">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="close"
      >
        <div
          class="surface-card rounded-2xl shadow-2xl max-w-lg w-full max-h-[95vh] sm:max-h-[90vh] overflow-y-auto scroll-thin"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <h3 class="text-base sm:text-lg font-semibold txt-primary">
              {{ memory ? $t('memories.edit.title') : $t('memories.create.title') }}
            </h3>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
              @click="close"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Mode Toggle (for both create and edit) -->
          <div class="px-4 sm:px-6 pt-4 sm:pt-6">
            <div class="flex items-center gap-2 p-1.5 surface-chip rounded-xl">
              <button
                type="button"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                :class="
                  mode === 'easy'
                    ? 'mode-toggle-active'
                    : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/10'
                "
                @click="switchToEasyMode"
              >
                <Icon icon="mdi:magic-staff" class="w-4 h-4" />
                {{ $t('memories.form.easyMode') }}
              </button>
              <button
                type="button"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                :class="
                  mode === 'advanced'
                    ? 'mode-toggle-active'
                    : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/10'
                "
                @click="mode = 'advanced'"
              >
                <Icon icon="mdi:form-textbox" class="w-4 h-4" />
                {{ $t('memories.form.advancedMode') }}
              </button>
            </div>
          </div>

          <!-- Easy Mode: Input Step (for both create and edit) -->
          <form
            v-if="mode === 'easy' && step === 'input'"
            class="p-4 sm:p-6 space-y-5"
            @submit.prevent="handleEasySubmit"
          >
            <!-- Animated Icon Header -->
            <div class="text-center mb-2">
              <div
                class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-brand/20 to-purple-500/20 flex items-center justify-center animate-pulse-slow"
              >
                <Icon icon="mdi:head-lightbulb" class="w-8 h-8 text-brand drop-shadow-lg" />
              </div>
              <p class="txt-secondary text-sm leading-relaxed">
                {{
                  memory
                    ? $t('memories.form.easyEditDescription')
                    : $t('memories.form.easyDescription')
                }}
              </p>
            </div>

            <!-- Current Memory Context (only for edit mode) -->
            <div v-if="memory" class="surface-chip rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <Icon icon="mdi:information-outline" class="w-4 h-4 txt-secondary" />
                <span class="text-xs font-medium txt-secondary uppercase tracking-wide">
                  {{ $t('memories.form.currentMemory') }}
                </span>
              </div>
              <div class="flex items-center gap-2 mb-1">
                <span class="pill text-xs">{{ memory.category }}</span>
                <span class="text-sm font-medium txt-primary">{{ memory.key }}</span>
              </div>
              <p class="txt-secondary text-sm">{{ memory.value }}</p>
            </div>

            <!-- Input Textarea -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ memory ? $t('memories.form.whatToChange') : $t('memories.form.whatToRemember') }}
              </label>
              <textarea
                v-model="easyInput"
                :placeholder="
                  memory
                    ? $t('memories.form.easyEditPlaceholder')
                    : $t('memories.form.easyPlaceholder')
                "
                rows="4"
                required
                class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none transition-all text-sm sm:text-base"
              ></textarea>
              <p class="mt-2 text-xs txt-secondary">
                {{ memory ? $t('memories.form.easyEditHint') : $t('memories.form.easyHint') }}
              </p>
            </div>

            <!-- Category Selection with Custom Option (only for create) -->
            <div v-if="!memory">
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.form.categoryOptional') }}
              </label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="cat in suggestedCategories"
                  :key="cat"
                  type="button"
                  class="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                  :class="
                    easyCategory === cat
                      ? 'bg-brand text-white'
                      : 'surface-chip txt-secondary hover:txt-primary'
                  "
                  @click="easyCategory = easyCategory === cat ? '' : cat"
                >
                  {{ $t(`memories.categories.${cat}`, cat) }}
                </button>
                <!-- Custom category button -->
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-full text-xs font-medium transition-all flex items-center gap-1"
                  :class="
                    showCustomCategory
                      ? 'bg-brand text-white'
                      : 'surface-chip txt-secondary hover:txt-primary border border-dashed border-current'
                  "
                  @click="toggleCustomCategory"
                >
                  <Icon icon="mdi:plus" class="w-3 h-3" />
                  {{ $t('memories.form.customCategory') }}
                </button>
              </div>
              <!-- Custom category input -->
              <div v-if="showCustomCategory" class="mt-3">
                <input
                  v-model="customCategoryInput"
                  type="text"
                  :placeholder="$t('memories.form.customCategoryPlaceholder')"
                  class="w-full px-4 py-2.5 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all text-sm"
                  @input="easyCategory = customCategoryInput"
                />
              </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-2">
              <button
                type="button"
                class="flex-1 btn-secondary px-4 py-2.5 rounded-xl font-medium transition-all"
                @click="close"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                type="submit"
                :disabled="isProcessing || !easyInput.trim()"
                class="flex-1 btn-primary px-4 py-2.5 rounded-xl font-medium flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <Icon v-if="isProcessing" icon="mdi:loading" class="w-4 h-4 animate-spin" />
                <Icon v-else icon="mdi:sparkles" class="w-4 h-4" />
                {{
                  isProcessing
                    ? $t('common.loading')
                    : memory
                      ? $t('memories.form.updateWithAI')
                      : $t('memories.form.createWithAI')
                }}
              </button>
            </div>
          </form>

          <!-- Easy Mode: Preview Step (for both create and edit) -->
          <div v-else-if="mode === 'easy' && step === 'preview'" class="p-4 sm:p-6">
            <!-- Preview Header -->
            <div class="text-center mb-6">
              <div
                class="w-14 h-14 mx-auto mb-3 rounded-xl bg-gradient-to-br from-green-500/20 to-emerald-500/20 flex items-center justify-center"
              >
                <Icon icon="mdi:check-circle" class="w-7 h-7 text-green-500" />
              </div>
              <h4 class="text-lg font-semibold txt-primary mb-1">
                {{ $t('memories.form.previewTitle') }}
              </h4>
              <p class="txt-secondary text-sm">
                {{ $t('memories.form.previewDescription') }}
              </p>
            </div>

            <!-- Actions Summary -->
            <div class="mb-3 text-center">
              <span class="text-sm txt-secondary">
                {{ parsedActions.length }}
                {{
                  parsedActions.length === 1
                    ? $t('memories.form.oneChange')
                    : $t('memories.form.multipleChanges')
                }}
              </span>
            </div>

            <!-- Preview Cards for each action -->
            <div class="space-y-3 mb-4 max-h-64 overflow-y-auto scroll-thin">
              <div
                v-for="(actionItem, index) in parsedActions"
                :key="index"
                class="surface-chip rounded-xl p-4"
              >
                <div class="flex items-start gap-3">
                  <!-- Action Icon -->
                  <div
                    class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                    :class="{
                      'bg-green-500/20': actionItem.action === 'create',
                      'bg-blue-500/20': actionItem.action === 'update',
                      'bg-red-500/20': actionItem.action === 'delete',
                    }"
                  >
                    <Icon
                      :icon="
                        actionItem.action === 'create'
                          ? 'mdi:plus'
                          : actionItem.action === 'update'
                            ? 'mdi:pencil'
                            : 'mdi:delete'
                      "
                      class="w-4 h-4"
                      :class="{
                        'text-green-600 dark:text-green-400': actionItem.action === 'create',
                        'text-blue-600 dark:text-blue-400': actionItem.action === 'update',
                        'text-red-600 dark:text-red-400': actionItem.action === 'delete',
                      }"
                    />
                  </div>

                  <!-- Memory Info -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span
                        class="px-2 py-0.5 rounded-full text-xs font-medium"
                        :class="{
                          'bg-green-500/20 text-green-600 dark:text-green-400':
                            actionItem.action === 'create',
                          'bg-blue-500/20 text-blue-600 dark:text-blue-400':
                            actionItem.action === 'update',
                          'bg-red-500/20 text-red-600 dark:text-red-400':
                            actionItem.action === 'delete',
                        }"
                      >
                        {{
                          actionItem.action === 'create'
                            ? $t('memories.form.actionCreate')
                            : actionItem.action === 'update'
                              ? $t('memories.form.actionUpdate')
                              : $t('memories.form.actionDelete')
                        }}
                      </span>
                      <span v-if="actionItem.memory" class="pill text-xs">{{
                        actionItem.memory.category
                      }}</span>
                    </div>

                    <template v-if="actionItem.memory">
                      <p class="text-sm font-medium txt-primary">{{ actionItem.memory.key }}</p>
                      <p class="txt-secondary text-sm">{{ actionItem.memory.value }}</p>
                    </template>

                    <p v-if="actionItem.reason" class="text-xs txt-secondary italic mt-1">
                      <Icon icon="mdi:information" class="w-3 h-3 inline mr-0.5" />
                      {{ actionItem.reason }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3">
              <button
                type="button"
                class="flex-1 btn-secondary px-4 py-2.5 rounded-xl font-medium transition-all"
                @click="step = 'input'"
              >
                <Icon icon="mdi:arrow-left" class="w-4 h-4 inline mr-1" />
                {{ $t('common.back') }}
              </button>
              <button
                type="button"
                class="flex-1 btn-primary px-4 py-2.5 rounded-xl font-medium flex items-center justify-center gap-2"
                @click="confirmAndSave"
              >
                <Icon icon="mdi:check" class="w-4 h-4" />
                {{ $t('memories.form.confirm') }}
              </button>
            </div>
          </div>

          <!-- Advanced Mode Form (also used for edit) -->
          <form v-else class="p-4 sm:p-6 space-y-4" @submit.prevent="handleAdvancedSubmit">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.category') }}
              </label>
              <input
                v-model="formData.category"
                type="text"
                :placeholder="$t('memories.create.categoryPlaceholder')"
                required
                list="category-suggestions"
                class="w-full px-4 py-2.5 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all"
              />
              <datalist id="category-suggestions">
                <option v-for="category in availableCategories" :key="category" :value="category" />
              </datalist>
              <p class="mt-1 text-xs txt-secondary">
                {{ $t('memories.create.categoryHint') }}
              </p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.key') }}
              </label>
              <input
                v-model="formData.key"
                type="text"
                required
                :placeholder="$t('memories.create.keyPlaceholder')"
                class="w-full px-4 py-2.5 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all"
              />
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.value') }}
              </label>
              <textarea
                v-model="formData.value"
                required
                :placeholder="$t('memories.create.valuePlaceholder')"
                rows="4"
                class="w-full px-4 py-2.5 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none transition-all"
              ></textarea>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-4">
              <button
                type="button"
                class="flex-1 btn-secondary px-4 py-2.5 rounded-xl font-medium transition-all"
                @click="close"
              >
                {{ $t('common.cancel') }}
              </button>
              <button type="submit" class="flex-1 btn-primary px-4 py-2.5 rounded-xl font-medium">
                {{ $t('common.save') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type {
  UserMemory,
  CreateMemoryRequest,
  UpdateMemoryRequest,
} from '@/services/api/userMemoriesApi'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { useNotification } from '@/composables/useNotification'
import { httpClient } from '@/services/api/httpClient'

interface ParsedAction {
  action: 'create' | 'update' | 'delete'
  memory?: {
    category: string
    key: string
    value: string
  }
  existingId?: number
  reason?: string
}

interface Props {
  isOpen: boolean
  memory?: UserMemory | null
  availableCategories?: string[]
}

interface Emits {
  (e: 'close'): void
  (e: 'save', data: CreateMemoryRequest | UpdateMemoryRequest): void
  (e: 'saveMultiple', actions: ParsedAction[]): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()
const { t } = useI18n()
const { teleportTarget } = useFullscreenTeleportTarget()
const { error } = useNotification()

const mode = ref<'easy' | 'advanced'>('easy')
const step = ref<'input' | 'preview'>('input')
const isProcessing = ref(false)
const easyInput = ref('')
const easyCategory = ref('')
const showCustomCategory = ref(false)
const customCategoryInput = ref('')
const parsedActions = ref<ParsedAction[]>([])
const similarMemories = ref<UserMemory[]>([])

const formData = ref({
  category: 'preferences',
  key: '',
  value: '',
})

const suggestedCategories = computed(() => {
  const defaults = ['preferences', 'personal', 'work', 'projects']
  const existing = props.availableCategories || []
  return [...new Set([...defaults, ...existing])].slice(0, 5)
})

watch(
  () => props.memory,
  (memory) => {
    if (memory) {
      formData.value = {
        category: memory.category,
        key: memory.key,
        value: memory.value,
      }
      // Default to easy mode for both create and edit
      mode.value = 'easy'
      step.value = 'input'
      easyInput.value = ''
      parsedActions.value = []
    } else {
      formData.value = {
        category: 'preferences',
        key: '',
        value: '',
      }
      mode.value = 'easy'
      step.value = 'input'
      easyInput.value = ''
      easyCategory.value = ''
      showCustomCategory.value = false
      customCategoryInput.value = ''
      parsedActions.value = []
      similarMemories.value = []
    }
  },
  { immediate: true }
)

function switchToEasyMode() {
  mode.value = 'easy'
  step.value = 'input'
  easyInput.value = ''
  parsedActions.value = []
}

watch(
  () => props.isOpen,
  (isOpen) => {
    if (!isOpen) {
      isProcessing.value = false
      step.value = 'input'
    }
  }
)

function toggleCustomCategory() {
  showCustomCategory.value = !showCustomCategory.value
  if (showCustomCategory.value) {
    easyCategory.value = ''
  } else {
    customCategoryInput.value = ''
  }
}

function close() {
  emit('close')
}

async function handleEasySubmit() {
  if (!easyInput.value.trim()) return

  isProcessing.value = true

  try {
    // Build request - include current memory context for edit mode
    const requestBody: Record<string, unknown> = {
      input: easyInput.value,
    }

    if (props.memory) {
      // For edit mode, provide the current memory context
      requestBody.input = `Current memory: ${props.memory.key} = "${props.memory.value}" (category: ${props.memory.category}). User wants to: ${easyInput.value}`
      requestBody.editMemoryId = props.memory.id
    } else {
      // For create mode, include suggested category
      requestBody.suggestedCategory = easyCategory.value || customCategoryInput.value || undefined
    }

    const response = await httpClient<{
      actions: ParsedAction[]
      similarMemories?: UserMemory[]
    }>('/api/v1/user/memories/parse', {
      method: 'POST',
      body: JSON.stringify(requestBody),
    })

    if (!response.actions || response.actions.length === 0) {
      throw new Error('AI did not return valid actions')
    }

    // For edit mode, ensure the action references the correct memory ID
    if (props.memory) {
      parsedActions.value = response.actions.map((action) => {
        // If this is an update without existingId, add the current memory's ID
        if (action.action === 'update' && !action.existingId) {
          return { ...action, existingId: props.memory!.id }
        }
        return action
      })
    } else {
      parsedActions.value = response.actions
    }

    similarMemories.value = response.similarMemories || []
    step.value = 'preview'
  } catch (err) {
    // No fallback - show error and stay on input step
    error(t('memories.form.aiUnavailable'))
  } finally {
    isProcessing.value = false
  }
}

function confirmAndSave() {
  if (parsedActions.value.length === 0) return

  // Emit all actions for the parent to process
  emit('saveMultiple', parsedActions.value)
}

function handleAdvancedSubmit() {
  emit('save', {
    category: formData.value.category,
    key: formData.value.key,
    value: formData.value.value,
  })
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@keyframes pulse-slow {
  0%,
  100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.85;
    transform: scale(1.02);
  }
}

.animate-pulse-slow {
  animation: pulse-slow 3s ease-in-out infinite;
}

.mode-toggle-active {
  background-color: var(--brand);
  color: white;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}
</style>
