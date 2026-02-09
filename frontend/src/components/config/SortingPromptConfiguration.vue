<template>
  <div class="space-y-6" data-testid="page-config-sorting-prompt">
    <div class="surface-card p-6" data-testid="section-overview">
      <h2 class="text-2xl font-semibold txt-primary mb-3">
        {{ $t('config.sortingPrompt.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-2">
        {{ $t('config.sortingPrompt.description') }}
      </p>
      <p class="text-sm txt-secondary">
        See the
        <router-link
          to="/config/task-prompts"
          class="text-[var(--brand)] hover:underline font-medium"
        >
          Prompt Editor
        </router-link>
        {{ $t('config.sortingPrompt.promptEditorLink') }}.
      </p>
    </div>

    <div class="surface-card overflow-hidden" data-testid="section-tabs">
      <div class="flex border-b border-light-border/30 dark:border-dark-border/20">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          :class="[
            'px-6 py-3 text-sm font-medium transition-colors relative',
            activeTab === tab.id
              ? 'txt-primary bg-[var(--brand)]/5 border-b-2 border-[var(--brand)]'
              : 'txt-secondary hover:bg-black/5 dark:hover:bg-white/5',
          ]"
          data-testid="btn-tab"
          @click="activeTab = tab.id"
        >
          {{ tab.label }}
        </button>
      </div>

      <div class="p-6">
        <div v-if="activeTab === 'rendered'" data-testid="section-rendered">
          <div class="space-y-6">
            <div>
              <h3 class="text-xl font-semibold txt-primary mb-3">
                {{ introTitle }}
              </h3>
              <!-- eslint-disable-next-line vue/no-v-html -->
              <div class="markdown-content txt-secondary text-sm" v-html="introHtml"></div>
            </div>

            <div>
              <h4 class="text-lg font-semibold txt-primary mb-3">
                {{ $t('config.sortingPrompt.yourTasks') }}
              </h4>
              <!-- eslint-disable-next-line vue/no-v-html -->
              <div class="markdown-content txt-secondary text-sm" v-html="tasksHtml"></div>
            </div>

            <div>
              <h4 class="text-lg font-semibold txt-primary mb-3">
                Your tasks in every new message are to:
              </h4>
              <ol class="space-y-4 txt-secondary text-sm">
                <li
                  v-for="(instructionHtml, index) in instructionHtmls"
                  :key="`instruction-${index}`"
                  class="flex gap-3"
                >
                  <span class="font-semibold txt-primary">{{ index + 1 }}.</span>
                  <div>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div class="markdown-content" v-html="instructionHtml"></div>
                    <ul v-if="index === 1" class="space-y-3 ml-4 mt-3">
                      <li
                        v-for="category in sortingPrompt.categories"
                        :key="category.name"
                        class="pl-4 border-l-2 border-[var(--brand)]/30"
                      >
                        <div class="flex items-center gap-2 mb-1">
                          <router-link
                            :to="getPromptLink(category.name)"
                            class="font-semibold txt-primary hover:underline"
                          >
                            {{ category.name }}
                          </router-link>
                          <span v-if="category.type === 'default'" class="text-xs txt-secondary"
                            >(default)</span
                          >
                          <span v-else class="text-xs text-purple-500">(custom)</span>
                        </div>
                        <p class="text-sm txt-secondary">{{ category.description }}</p>
                      </li>
                    </ul>
                  </div>
                </li>
              </ol>
            </div>
            <hr class="border-light-border/30 dark:border-dark-border/20" />
          </div>
        </div>

        <div v-else-if="activeTab === 'source'" data-testid="section-source">
          <div class="space-y-4">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('config.sortingPrompt.tabSource') }}
              </h3>
              <button
                class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-toggle-mode"
                :disabled="!canEdit || loading"
                @click="toggleEditMode"
              >
                <PencilIcon v-if="!editMode" class="w-4 h-4 inline mr-1" />
                <EyeIcon v-else class="w-4 h-4 inline mr-1" />
                {{ editMode ? 'View Mode' : 'Edit Mode' }}
              </button>
            </div>

            <div
              v-if="!editMode"
              class="surface-chip p-6 rounded border border-light-border/30 dark:border-dark-border/20"
              data-testid="section-prompt-preview"
            >
              <pre class="whitespace-pre-wrap font-mono text-xs txt-primary leading-relaxed">{{
                sortingPrompt.promptContent
              }}</pre>
            </div>

            <textarea
              v-else
              v-model="sortingPrompt.promptContent"
              rows="25"
              class="w-full px-4 py-3 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono"
              data-testid="input-prompt"
            />

            <div v-if="editMode" class="flex gap-3">
              <button
                class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-save"
                :disabled="saving || loading"
                @click="savePrompt"
              >
                <CheckIcon class="w-5 h-5" />
                {{ $t('config.sortingPrompt.savePrompt') }}
              </button>
              <button
                class="px-6 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-reset"
                :disabled="saving || loading"
                @click="resetPrompt"
              >
                {{ $t('config.sortingPrompt.resetPrompt') }}
              </button>
            </div>
          </div>
        </div>

        <div v-else-if="activeTab === 'json'" data-testid="section-json">
          <div class="space-y-4">
            <div>
              <h3 class="text-lg font-semibold txt-primary mb-2">
                {{ $t('config.sortingPrompt.tabJson') }}
              </h3>
              <p class="txt-secondary text-sm mb-3">
                {{ $t('config.sortingPrompt.jsonDescription') }}
              </p>
              <p class="txt-secondary text-sm mb-3">
                {{ $t('config.sortingPrompt.jsonNote') }}
              </p>
              <p class="txt-secondary text-sm font-medium mb-4">
                {{ $t('config.sortingPrompt.jsonExample') }}
              </p>
            </div>

            <div
              class="bg-black/90 dark:bg-black/50 rounded-lg p-4 font-mono text-sm text-green-400 overflow-x-auto"
            >
              <pre>{{ sortingPrompt.jsonExample }}</pre>
            </div>

            <div class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-lg">
              <p class="text-sm txt-primary">
                <InformationCircleIcon class="w-5 h-5 text-cyan-500 inline mr-2" />
                {{ $t('config.sortingPrompt.btopicNote') }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { PencilIcon, EyeIcon, CheckIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import { mockSortingPrompt } from '@/mocks/sortingPrompt'
import type { SortingPromptData } from '@/mocks/sortingPrompt'
import { promptsApi } from '@/services/api/promptsApi'
import type { SortingPromptPayload } from '@/services/api/promptsApi'
import { useNotification } from '@/composables/useNotification'
import { useAuthStore } from '@/stores/auth'
import { getMarkdownRenderer } from '@/composables/useMarkdown'

const activeTab = ref('rendered')
const editMode = ref(false)
const sortingPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const originalPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const loading = ref(false)
const saving = ref(false)

const authStore = useAuthStore()
const canEdit = computed(() => authStore.isAdmin)
const { success, error: showError, warning } = useNotification()
const { locale } = useI18n()
const markdownRenderer = getMarkdownRenderer()
const getPromptLink = (topic: string) => `/config/task-prompts?topic=${encodeURIComponent(topic)}`

const renderedPromptText = computed(
  () => sortingPrompt.value.renderedPrompt || sortingPrompt.value.promptContent || ''
)

const extractIntroSection = (
  promptText: string
): {
  title: string
  body: string
} => {
  const match = promptText.match(/#+\s*Your tasks/i)
  const intro =
    !match || match.index === undefined
      ? promptText.trim()
      : promptText.slice(0, match.index).trim()
  const lines = intro.split('\n')
  const normalized = lines.map((line) => line.trim()).filter((line) => line.length > 0)

  let title = ''
  const cleaned: string[] = []

  for (const line of normalized) {
    const headingMatch = line.match(/^#{1,6}\s+(.*)$/)
    if (!title && headingMatch?.[1]) {
      title = headingMatch[1].trim()
      continue
    }

    const lower = line.toLowerCase()
    const last = cleaned[cleaned.length - 1]?.toLowerCase()
    if (lower === 'set btopic and tools in json' && last === lower) {
      continue
    }
    if (lower === 'set btopic and tools in json' && cleaned.length === 0) {
      title = title || line
      continue
    }
    cleaned.push(line)
  }

  return {
    title: title || 'Preprocessor',
    body: cleaned.join('\n').trim(),
  }
}

const extractTasksBody = (promptText: string): string => {
  const startMatch = promptText.match(/#+\s*Your tasks/i)
  if (!startMatch || startMatch.index === undefined) {
    return ''
  }
  const startIndex = startMatch.index + startMatch[0].length
  const rest = promptText.slice(startIndex)
  const endMatch = rest.match(/Your tasks in every new message are to:/i)
  const endIndex = endMatch && endMatch.index !== undefined ? endMatch.index : rest.length
  return rest.slice(0, endIndex).trim()
}

const introSection = computed(() => extractIntroSection(renderedPromptText.value))
const introTitle = computed(() => introSection.value.title)
const introHtml = computed(() => markdownRenderer.render(introSection.value.body))
const tasksHtml = computed(() =>
  markdownRenderer.render(extractTasksBody(renderedPromptText.value))
)
const instructionHtmls = computed(() =>
  sortingPrompt.value.instructions.map((instruction) => markdownRenderer.render(instruction))
)

const tabs = [
  { id: 'rendered', label: 'Rendered Result' },
  { id: 'source', label: 'Prompt Source' },
  { id: 'json', label: 'JSON Object' },
]

const extractTasks = (promptText: string): string => {
  const section = promptText.split('# Your tasks')[1]
  if (!section) {
    return ''
  }

  const lines = section
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
  return lines[0] ?? ''
}

const extractInstructions = (promptText: string): string[] => {
  const match = promptText.match(
    /Your tasks in every new message are to:\s*([\s\S]*?)\n# Answer format/i
  )
  if (!match) {
    return []
  }

  const section = match[1].trim()
  const sanitizeInstruction = (instruction: string): string => {
    const lines = instruction
      .split('\n')
      .filter((line) => !line.trim().startsWith('- "'))
      .map((line) => line.trimEnd())
    return lines.join('\n').trim()
  }

  return section
    .split(/\n(?=\d+\.\s)/)
    .map((item) => item.replace(/^\d+\.\s*/, '').trim())
    .map((item) => sanitizeInstruction(item))
    .filter((item) => item.length > 0)
}

const mapSortingPrompt = (payload: SortingPromptPayload): SortingPromptData => {
  const tasks = extractTasks(payload.renderedPrompt)
  const instructions = extractInstructions(payload.renderedPrompt)

  return {
    id: payload.id,
    description: payload.shortDescription || mockSortingPrompt.description,
    tasks: tasks || mockSortingPrompt.tasks,
    categories: payload.categories,
    instructions: instructions.length ? instructions : mockSortingPrompt.instructions,
    promptContent: payload.prompt,
    renderedPrompt: payload.renderedPrompt,
    jsonExample: mockSortingPrompt.jsonExample,
  }
}

const loadSortingPrompt = async () => {
  loading.value = true
  try {
    const prompt = await promptsApi.getSortingPrompt(locale.value || 'en')
    const mapped = mapSortingPrompt(prompt)
    sortingPrompt.value = { ...mapped }
    originalPrompt.value = { ...mapped }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Failed to load sorting prompt'
    showError(message)
    sortingPrompt.value = { ...mockSortingPrompt }
    originalPrompt.value = { ...mockSortingPrompt }
  } finally {
    loading.value = false
  }
}

const toggleEditMode = () => {
  if (!canEdit.value) {
    warning('Admin access required to edit the sorting prompt.')
    return
  }
  editMode.value = !editMode.value
}

const savePrompt = async () => {
  if (!canEdit.value) {
    warning('Admin access required to edit the sorting prompt.')
    return
  }

  saving.value = true
  try {
    await promptsApi.updateSortingPrompt(sortingPrompt.value.promptContent)
    success('Sorting prompt saved.')
    await loadSortingPrompt()
    editMode.value = false
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Failed to save sorting prompt'
    showError(message)
  } finally {
    saving.value = false
  }
}

const resetPrompt = () => {
  sortingPrompt.value = { ...originalPrompt.value }
  editMode.value = false
}

watch(locale, () => {
  loadSortingPrompt()
})

onMounted(() => {
  loadSortingPrompt()
})
</script>
