<template>
  <div class="space-y-6" data-testid="page-config-routing">
    <!-- Header: what routing is + how to add custom prompts -->
    <div class="surface-card p-6" data-testid="section-routing-overview">
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10">
          <Icon icon="heroicons:share" class="w-6 h-6 text-[var(--brand)]" />
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-2xl font-semibold txt-primary mb-1">
            {{ $t('config.routing.title') }}
          </h2>
          <p class="txt-secondary text-sm">
            {{ $t('config.routing.flowBody') }}
          </p>

          <!-- Prominent callout: custom prompts live in Task Prompts -->
          <div
            class="mt-4 p-4 rounded-lg bg-[var(--brand)]/5 border border-[var(--brand)]/20 flex items-start gap-3"
            data-testid="callout-custom-prompts"
          >
            <Icon
              icon="heroicons:light-bulb"
              class="w-5 h-5 text-[var(--brand)] flex-shrink-0 mt-0.5"
            />
            <p class="text-sm txt-secondary leading-relaxed">
              {{ $t('config.routing.customCalloutBody') }}
              <router-link
                to="/config/task-prompts"
                class="text-[var(--brand)] hover:underline font-medium"
              >
                {{ $t('config.routing.customCalloutLink') }}
              </router-link>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Master switch (admin only): turn the multi-task planner on/off -->
    <div v-if="isAdmin" class="surface-card overflow-hidden" data-testid="section-routing-master">
      <div class="p-6">
        <div class="flex items-start gap-4 flex-wrap">
          <div
            class="p-2 rounded-lg bg-[var(--brand)]/10 flex-shrink-0 flex items-center justify-center w-10 h-10"
          >
            <Icon icon="heroicons:bolt" class="w-6 h-6 text-[var(--brand)]" />
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="text-lg font-semibold txt-primary mb-1">
              {{ $t('config.routing.masterTitle') }}
            </h3>
            <p class="text-sm txt-secondary">
              {{ $t('config.routing.masterDesc') }}
            </p>
            <p class="text-xs txt-secondary mt-2">
              {{ $t('config.routing.masterHint') }}
            </p>
          </div>
          <label
            class="inline-flex items-center gap-3 cursor-pointer flex-shrink-0"
            data-testid="toggle-multitask-enabled"
          >
            <span class="text-sm font-medium txt-primary">
              {{
                multitaskEnabled ? $t('config.routing.masterOn') : $t('config.routing.masterOff')
              }}
            </span>
            <span class="relative inline-flex">
              <input
                type="checkbox"
                class="sr-only peer"
                :checked="multitaskEnabled"
                :disabled="togglingMultitask"
                data-testid="toggle-multitask-input"
                @change="onToggleMultitask(($event.target as HTMLInputElement).checked)"
              />
              <span
                class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand)] peer-disabled:opacity-50 transition-colors"
              ></span>
              <span
                class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"
              ></span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <!-- Planner prompt (admin only): the instructions the planner follows -->
    <div v-if="isAdmin" class="surface-card overflow-hidden" data-testid="section-routing-planner">
      <div class="p-6 border-b border-light-border/30 dark:border-dark-border/20">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div class="flex items-start gap-3 min-w-0">
            <div class="p-2 rounded-lg bg-[var(--brand)]/10 flex-shrink-0">
              <Icon icon="heroicons:cpu-chip" class="w-5 h-5 text-[var(--brand)]" />
            </div>
            <div class="min-w-0">
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('config.routing.plannerTitle') }}
              </h3>
              <p class="text-sm txt-secondary mt-0.5">
                {{ $t('config.routing.plannerDesc') }}
              </p>
            </div>
          </div>
          <button
            class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors text-sm disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-planner-toggle-mode"
            :disabled="loadingPlanner"
            @click="togglePlannerEditMode"
          >
            <PencilIcon v-if="!plannerEditMode" class="w-4 h-4 inline mr-1" />
            <EyeIcon v-else class="w-4 h-4 inline mr-1" />
            {{
              plannerEditMode ? $t('config.routing.plannerView') : $t('config.routing.plannerEdit')
            }}
          </button>
        </div>
      </div>

      <div class="p-6 space-y-4">
        <div v-if="!plannerEditMode" class="flex gap-2">
          <button
            v-for="tab in ['rendered', 'source']"
            :key="tab"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              plannerTab === tab
                ? 'bg-[var(--brand)]/10 text-[var(--brand)]'
                : 'surface-chip txt-secondary hover:txt-primary',
            ]"
            data-testid="btn-planner-tab"
            @click="plannerTab = tab as 'rendered' | 'source'"
          >
            {{
              tab === 'rendered'
                ? $t('config.routing.plannerRendered')
                : $t('config.routing.plannerSource')
            }}
          </button>
        </div>

        <div
          v-if="!plannerEditMode"
          class="surface-chip p-6 rounded border border-light-border/30 dark:border-dark-border/20"
          data-testid="section-planner-preview"
        >
          <pre class="whitespace-pre-wrap font-mono text-xs txt-primary leading-relaxed">{{
            plannerTab === 'rendered' ? plannerPrompt.renderedPrompt : plannerPrompt.prompt
          }}</pre>
        </div>

        <textarea
          v-else
          v-model="plannerPrompt.prompt"
          rows="22"
          class="w-full px-4 py-3 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono"
          data-testid="input-planner-prompt"
        />

        <div v-if="plannerEditMode" class="flex gap-3">
          <button
            class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-planner-save"
            :disabled="savingPlanner || loadingPlanner"
            @click="savePlannerPrompt"
          >
            <CheckIcon class="w-5 h-5" />
            {{ $t('config.routing.plannerSave') }}
          </button>
          <button
            class="px-6 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-planner-reset"
            :disabled="savingPlanner || loadingPlanner"
            @click="resetPlannerPrompt"
          >
            {{ $t('config.routing.plannerReset') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Legacy fallback (admin only) — collapsed by default -->
    <div v-if="isAdmin" class="space-y-6" data-testid="section-routing-legacy">
      <button
        type="button"
        class="surface-card w-full px-6 py-4 flex items-center justify-between hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        data-testid="btn-legacy-toggle"
        @click="legacyOpen = !legacyOpen"
      >
        <div class="flex items-start gap-3 text-left">
          <Icon icon="heroicons:archive-box" class="w-5 h-5 text-[var(--brand)] mt-0.5" />
          <div>
            <h3 class="text-lg font-semibold txt-primary">
              {{ $t('config.routing.legacyTitle') }}
            </h3>
            <p class="text-xs txt-secondary mt-0.5">
              {{ $t('config.routing.legacyDesc') }}
            </p>
          </div>
        </div>
        <Icon
          :icon="legacyOpen ? 'heroicons:chevron-up' : 'heroicons:chevron-down'"
          class="w-5 h-5 txt-secondary flex-shrink-0"
        />
      </button>

      <div v-show="legacyOpen" class="space-y-6">
        <!-- AI Fallback Prompt (collapsed accordion) -->
        <div class="surface-card overflow-hidden" data-testid="section-routing-fallback">
          <button
            type="button"
            class="w-full px-6 py-4 flex items-center justify-between hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            data-testid="btn-fallback-toggle"
            @click="fallbackOpen = !fallbackOpen"
          >
            <div class="flex items-start gap-3 text-left">
              <Icon icon="heroicons:cpu-chip" class="w-5 h-5 text-[var(--brand)] mt-0.5" />
              <div>
                <h3 class="text-lg font-semibold txt-primary">
                  {{ $t('config.routing.fallbackTitle') }}
                </h3>
                <p class="text-xs txt-secondary mt-0.5">
                  {{ $t('config.routing.fallbackSubtitle') }}
                </p>
              </div>
            </div>
            <Icon
              :icon="fallbackOpen ? 'heroicons:chevron-up' : 'heroicons:chevron-down'"
              class="w-5 h-5 txt-secondary flex-shrink-0"
            />
          </button>

          <div
            v-if="fallbackOpen"
            class="border-t border-light-border/30 dark:border-dark-border/20"
          >
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
                                <span
                                  v-if="category.type === 'default'"
                                  class="text-xs txt-secondary"
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
                    <pre
                      class="whitespace-pre-wrap font-mono text-xs txt-primary leading-relaxed"
                      >{{ sortingPrompt.promptContent }}</pre
                    >
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
          <!-- /AI Fallback Prompt -->
        </div>
        <!-- /legacy disclosure body -->
      </div>
      <!-- /Legacy fallback -->
    </div>
    <!-- /page-config-routing -->
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { PencilIcon, EyeIcon, CheckIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import { mockSortingPrompt } from '@/mocks/sortingPrompt'
import type { SortingPromptData } from '@/mocks/sortingPrompt'
import { promptsApi } from '@/services/api/promptsApi'
import type { SortingPromptPayload } from '@/services/api/promptsApi'
import { getConfigValues, updateConfigValue } from '@/services/api/adminConfigApi'
import { useNotification } from '@/composables/useNotification'
import { useAuthStore } from '@/stores/auth'
import { getMarkdownRenderer } from '@/composables/useMarkdown'

// --- Multi-task routing master switch (admin/global) ------------------------
// Reads/writes BCONFIG MULTITASK/ROUTING_ENABLED via the admin config API.
// This is the primary router; when OFF, the legacy AI sorter handles routing
// (see the collapsed "Legacy fallback" section).
const multitaskEnabled = ref(false)
const togglingMultitask = ref(false)

// --- Planner prompt (tools:plan) editor state ------------------------------
const plannerPrompt = ref<{ prompt: string; renderedPrompt: string }>({
  prompt: '',
  renderedPrompt: '',
})
const plannerOriginal = ref('')
const plannerTab = ref<'rendered' | 'source'>('rendered')
const plannerEditMode = ref(false)
const loadingPlanner = ref(false)
const savingPlanner = ref(false)

// --- Legacy fallback disclosure (collapsed by default) ----------------------
const legacyOpen = ref(false)

// --- AI-fallback prompt state (legacy single-topic sorter) -----------------
const fallbackOpen = ref(false)
const activeTab = ref('rendered')
const editMode = ref(false)
const sortingPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const originalPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const loading = ref(false)
const saving = ref(false)

const authStore = useAuthStore()
const isAdmin = computed(() => authStore.isAdmin)
const canEdit = computed(() => authStore.isAdmin)
const { success, error: showError, warning } = useNotification()
const { t, locale } = useI18n()
const markdownRenderer = getMarkdownRenderer()

const getPromptLink = (topic: string) => `/config/task-prompts?topic=${encodeURIComponent(topic)}`

// --- AI-fallback markdown rendering (kept verbatim from previous component) -
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
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to load sorting prompt'
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
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to save sorting prompt'
    showError(message)
  } finally {
    saving.value = false
  }
}

const resetPrompt = () => {
  sortingPrompt.value = { ...originalPrompt.value }
  editMode.value = false
}

// --- Routing toggle loading + persistence ----------------------------------
const parseBoolConfigValue = (raw: string | undefined): boolean =>
  ['true', '1', 'yes', 'on'].includes((raw ?? 'false').toLowerCase())

const loadRoutingToggles = async () => {
  if (!isAdmin.value) return
  try {
    const values = await getConfigValues()
    multitaskEnabled.value = parseBoolConfigValue(values['MULTITASK_ROUTING_ENABLED']?.value)
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to load routing configuration'
    showError(message)
  }
}

// --- Multi-task routing master switch ---------------------------------------
const onToggleMultitask = async (next: boolean) => {
  if (!isAdmin.value) return

  togglingMultitask.value = true
  const previous = multitaskEnabled.value
  multitaskEnabled.value = next
  try {
    const result = await updateConfigValue('MULTITASK_ROUTING_ENABLED', next ? 'true' : 'false')
    if (!result.success) {
      throw new Error(result.error || 'Failed to update multi-task routing state')
    }
    success(
      next ? t('config.routing.masterEnabledNotice') : t('config.routing.masterDisabledNotice')
    )
  } catch (err) {
    multitaskEnabled.value = previous
    const message = err instanceof Error ? err.message : 'Failed to update multi-task routing state'
    showError(message)
  } finally {
    togglingMultitask.value = false
  }
}

// --- Planner prompt (tools:plan) load / save --------------------------------
const loadPlannerPrompt = async () => {
  loadingPlanner.value = true
  try {
    const data = await promptsApi.getPlanningPrompt()
    plannerPrompt.value = { prompt: data.prompt, renderedPrompt: data.renderedPrompt }
    plannerOriginal.value = data.prompt
  } catch (err) {
    const message = err instanceof Error ? err.message : t('config.routing.plannerLoadFailed')
    showError(message)
  } finally {
    loadingPlanner.value = false
  }
}

const togglePlannerEditMode = () => {
  if (!canEdit.value) {
    warning(t('config.routing.plannerAdminRequired'))
    return
  }
  plannerEditMode.value = !plannerEditMode.value
  if (plannerEditMode.value) {
    plannerTab.value = 'source'
  }
}

const savePlannerPrompt = async () => {
  if (!canEdit.value) {
    warning(t('config.routing.plannerAdminRequired'))
    return
  }
  savingPlanner.value = true
  try {
    await promptsApi.updatePlanningPrompt(plannerPrompt.value.prompt)
    success(t('config.routing.plannerSaved'))
    await loadPlannerPrompt()
    plannerEditMode.value = false
    plannerTab.value = 'rendered'
  } catch (err) {
    const message = err instanceof Error ? err.message : t('config.routing.plannerSaveFailed')
    showError(message)
  } finally {
    savingPlanner.value = false
  }
}

const resetPlannerPrompt = () => {
  plannerPrompt.value = { ...plannerPrompt.value, prompt: plannerOriginal.value }
  plannerEditMode.value = false
  plannerTab.value = 'rendered'
}

watch(locale, () => {
  loadSortingPrompt()
})

onMounted(() => {
  loadSortingPrompt()
  if (isAdmin.value) {
    loadRoutingToggles()
    loadPlannerPrompt()
  }
})
</script>
