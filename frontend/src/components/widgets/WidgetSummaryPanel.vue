<template>
  <div :class="compact ? '' : 'surface-card p-4 lg:p-6'">
    <!-- Upgrade Required Notice (for non-Team users) -->
    <div v-if="!isTeam" class="text-center py-8">
      <div
        class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-500/20 to-pink-500/20 flex items-center justify-center mx-auto mb-4"
      >
        <Icon icon="heroicons:sparkles" class="w-8 h-8 text-purple-500" />
      </div>
      <h3 class="text-lg font-semibold txt-primary mb-2">{{ $t('summary.teamRequired') }}</h3>
      <p class="text-sm txt-secondary mb-6 max-w-sm mx-auto">
        {{ $t('summary.teamRequiredDescription') }}
      </p>
      <button
        class="px-6 py-2.5 rounded-lg bg-gradient-to-r from-purple-500 to-pink-500 text-white font-medium hover:from-purple-600 hover:to-pink-600 transition-all flex items-center gap-2 mx-auto"
        @click="goToUpgrade"
      >
        <Icon icon="heroicons:arrow-up-circle" class="w-5 h-5" />
        {{ $t('summary.upgrade') }}
      </button>
    </div>

    <!-- Main Content (for Team+ users) -->
    <template v-else>
      <!-- Header with saved summaries toggle -->
      <div class="flex items-center justify-between mb-4">
        <h3 v-if="!compact" class="text-lg font-semibold txt-primary flex items-center gap-2">
          <Icon icon="heroicons:chart-bar" class="w-5 h-5 txt-brand" />
          {{ $t('summary.title') }}
        </h3>
        <div class="flex items-center gap-2">
          <!-- Saved Summaries Button -->
          <button
            v-if="savedSummaries.length > 0"
            class="px-3 py-1.5 rounded-lg text-xs font-medium flex items-center gap-1.5 transition-colors"
            :class="
              showSavedSummaries
                ? 'bg-[var(--brand)] text-white'
                : 'surface-chip txt-secondary hover:txt-primary'
            "
            @click="showSavedSummaries = !showSavedSummaries"
          >
            <Icon icon="heroicons:clock" class="w-3.5 h-3.5" />
            {{ $t('summary.history') }} ({{ savedSummaries.length }})
          </button>
          <!-- Back to generator button (when viewing a saved summary) -->
          <button
            v-if="selectedSavedSummary"
            class="px-3 py-1.5 rounded-lg text-xs font-medium surface-chip txt-secondary hover:txt-primary flex items-center gap-1.5 transition-colors"
            @click="clearSummary"
          >
            <Icon icon="heroicons:arrow-left" class="w-3.5 h-3.5" />
            {{ $t('summary.newAnalysis') }}
          </button>
        </div>
      </div>

    <!-- Saved Summaries List -->
    <div
      v-if="showSavedSummaries"
      class="mb-4 p-3 rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20"
    >
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-medium txt-secondary">{{ $t('summary.savedSummaries') }}</span>
        <button
          class="text-xs txt-secondary hover:txt-primary"
          @click="showSavedSummaries = false"
        >
          <Icon icon="heroicons:x-mark" class="w-4 h-4" />
        </button>
      </div>
      <div v-if="loadingSummaries" class="text-center py-4">
        <Icon icon="heroicons:arrow-path" class="w-5 h-5 txt-secondary animate-spin" />
      </div>
      <div v-else class="space-y-1.5 max-h-48 overflow-y-auto scroll-thin">
        <button
          v-for="s in savedSummaries"
          :key="s.id"
          class="w-full p-2.5 rounded-lg text-left transition-colors"
          :class="
            selectedSavedSummary?.id === s.id
              ? 'bg-[var(--brand-alpha-light)] border border-[var(--brand)]/30'
              : 'hover:bg-black/5 dark:hover:bg-white/5'
          "
          @click="selectSavedSummary(s)"
        >
          <div class="flex items-center justify-between gap-2 mb-1">
            <div class="text-sm font-medium txt-primary">
              {{ s.dateRange || s.formattedDate || formatDateNumber(s.date!) }}
            </div>
            <div class="text-xs txt-secondary whitespace-nowrap">
              {{ formatTimestamp(s.created!) }}
            </div>
          </div>
          <div class="text-xs txt-secondary">
            {{ s.sessionCount }} {{ $t('summary.sessions') }} · {{ s.messageCount }}
            {{ $t('summary.messages') }}
          </div>
        </button>
      </div>
    </div>

    <!-- Selection Info -->
    <div
      v-if="selectedSessionIds.length > 0 && !selectedSavedSummary"
      class="mb-4 p-3 rounded-lg bg-[var(--brand-alpha-light)] border border-[var(--brand)]/30"
    >
      <div class="flex items-center gap-2">
        <Icon icon="heroicons:check-circle" class="w-5 h-5 txt-brand" />
        <span class="text-sm font-medium txt-brand">
          {{ $t('summary.analyzingSelected', { count: selectedSessionIds.length }) }}
        </span>
      </div>
    </div>

    <!-- Date Range Selection (only when no sessions selected and not viewing saved) -->
    <div v-if="selectedSessionIds.length === 0 && !selectedSavedSummary" class="mb-4 space-y-3">
      <div class="flex items-center gap-2">
        <div class="flex-1">
          <label class="block text-xs txt-secondary mb-1">{{ $t('summary.from') }}</label>
          <input
            v-model="fromDate"
            type="date"
            class="w-full px-3 py-2 rounded-lg surface-chip text-sm txt-primary"
          />
        </div>
        <div class="flex-1">
          <label class="block text-xs txt-secondary mb-1">{{ $t('summary.to') }}</label>
          <input
            v-model="toDate"
            type="date"
            class="w-full px-3 py-2 rounded-lg surface-chip text-sm txt-primary"
          />
        </div>
      </div>
    </div>

    <!-- Generate Button (hidden when viewing saved summary) -->
    <button
      v-if="!selectedSavedSummary"
      :disabled="generating"
      class="w-full mb-4 px-4 py-2.5 rounded-lg btn-primary text-sm font-medium flex items-center justify-center gap-2 disabled:opacity-50"
      @click="() => generateAnalysis()"
    >
      <Icon
        :icon="generating ? 'heroicons:arrow-path' : 'heroicons:sparkles'"
        :class="['w-4 h-4', generating && 'animate-spin']"
      />
      {{ generating ? $t('summary.generating') : $t('summary.generateAI') }}
    </button>

    <!-- Regenerate Button (when viewing saved summary) -->
    <button
      v-else
      :disabled="generating"
      class="w-full mb-4 px-4 py-2.5 rounded-lg surface-chip text-sm font-medium flex items-center justify-center gap-2 txt-secondary hover:txt-primary disabled:opacity-50 transition-colors"
      @click="() => generateAnalysis(true)"
    >
      <Icon
        :icon="generating ? 'heroicons:arrow-path' : 'heroicons:arrow-path'"
        :class="['w-4 h-4', generating && 'animate-spin']"
      />
      {{ generating ? $t('summary.generating') : $t('summary.regenerate') }}
    </button>

    <!-- Existing Summary Found Info -->
    <div
      v-if="existingSummaryFound && !generating"
      class="mb-4 p-3 rounded-lg bg-blue-500/10 border border-blue-500/30 flex items-start gap-2"
    >
      <Icon icon="heroicons:information-circle" class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" />
      <div class="flex-1">
        <p class="text-sm text-blue-600 dark:text-blue-400">{{ $t('summary.existingFoundInfo') }}</p>
        <button
          class="text-xs text-blue-500 hover:text-blue-600 underline mt-1"
          @click="() => generateAnalysis(true)"
        >
          {{ $t('summary.regenerateAnyway') }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="generating" class="text-center py-8">
      <div
        class="animate-spin w-8 h-8 border-4 border-[var(--brand)] border-t-transparent rounded-full mx-auto mb-4"
      ></div>
      <p class="txt-secondary text-sm">{{ $t('summary.aiAnalyzing') }}</p>
    </div>

    <!-- No Summary -->
    <div v-else-if="!summary" class="text-center py-8">
      <Icon
        icon="heroicons:document-chart-bar"
        class="w-12 h-12 txt-secondary opacity-30 mx-auto mb-4"
      />
      <p class="txt-secondary text-sm">{{ $t('summary.noSummary') }}</p>
      <p class="txt-secondary text-xs mt-2">{{ $t('summary.selectDateOrChats') }}</p>
    </div>

    <!-- Summary Content -->
    <template v-else>
      <!-- Stats Row -->
      <div
        :class="
          compact ? 'grid grid-cols-2 gap-2 mb-4' : 'grid grid-cols-2 gap-3 mb-6'
        "
      >
        <div
          :class="
            compact
              ? 'p-2 surface-chip rounded-lg text-center'
              : 'p-3 surface-chip rounded-lg text-center'
          "
        >
          <p class="text-xs txt-secondary">{{ $t('summary.sessions') }}</p>
          <p :class="compact ? 'text-lg font-bold txt-primary' : 'text-xl font-bold txt-primary'">
            {{ summary.sessionCount }}
          </p>
        </div>
        <div
          :class="
            compact
              ? 'p-2 surface-chip rounded-lg text-center'
              : 'p-3 surface-chip rounded-lg text-center'
          "
        >
          <p class="text-xs txt-secondary">{{ $t('summary.messages') }}</p>
          <p :class="compact ? 'text-lg font-bold txt-primary' : 'text-xl font-bold txt-primary'">
            {{ summary.messageCount }}
          </p>
        </div>
      </div>

      <!-- Sentiment Bar -->
      <div :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2">{{ $t('summary.sentiment') || 'Sentiment' }}</h4>
        <div class="h-4 rounded-full overflow-hidden flex bg-gray-200 dark:bg-gray-700">
          <div
            class="bg-green-500 transition-all"
            :style="{ width: summary.sentiment.positive + '%' }"
            :title="$t('summary.positive') + ': ' + summary.sentiment.positive + '%'"
          ></div>
          <div
            class="bg-gray-400 transition-all"
            :style="{ width: summary.sentiment.neutral + '%' }"
            :title="$t('summary.neutral') + ': ' + summary.sentiment.neutral + '%'"
          ></div>
          <div
            class="bg-red-500 transition-all"
            :style="{ width: summary.sentiment.negative + '%' }"
            :title="$t('summary.negative') + ': ' + summary.sentiment.negative + '%'"
          ></div>
        </div>
        <div class="flex justify-between mt-2 text-xs">
          <span class="flex items-center gap-1.5">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            {{ $t('summary.positive') }}: {{ summary.sentiment.positive }}%
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
            {{ $t('summary.neutral') }}: {{ summary.sentiment.neutral }}%
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
            {{ $t('summary.negative') }}: {{ summary.sentiment.negative }}%
          </span>
        </div>
      </div>

      <!-- Executive Summary -->
      <div :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2">{{ $t('summary.executiveSummary') }}</h4>
        <p :class="compact ? 'txt-secondary text-xs' : 'txt-secondary text-sm'">
          {{ summary.summary }}
        </p>
      </div>

      <!-- Topics -->
      <div v-if="summary.topics.length > 0" :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2">{{ $t('summary.mainTopics') }}</h4>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="topic in compact ? summary.topics.slice(0, 5) : summary.topics"
            :key="topic"
            class="px-2 py-0.5 rounded-full text-xs font-medium bg-[var(--brand-alpha-light)] txt-brand"
          >
            {{ topic }}
          </span>
        </div>
      </div>

      <!-- FAQs -->
      <div v-if="summary.faqs.length > 0" :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2">{{ $t('summary.frequentQuestions') }}</h4>
        <ul :class="compact ? 'space-y-1' : 'space-y-2'">
          <li
            v-for="faq in summary.faqs.slice(0, compact ? 3 : 5)"
            :key="faq.question"
            :class="compact ? 'flex items-center gap-2 text-xs' : 'flex items-center gap-2 text-sm'"
          >
            <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-blue-500/10 text-blue-600">
              {{ faq.frequency }}x
            </span>
            <span class="txt-secondary line-clamp-1">{{ faq.question }}</span>
          </li>
        </ul>
      </div>

      <!-- Issues -->
      <div v-if="summary.issues.length > 0" :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2 flex items-center gap-1">
          <Icon icon="heroicons:exclamation-triangle" class="w-4 h-4 text-yellow-500" />
          {{ $t('summary.issues') }}
        </h4>
        <ul class="space-y-1">
          <li
            v-for="issue in compact ? summary.issues.slice(0, 3) : summary.issues"
            :key="issue"
            :class="
              compact
                ? 'text-xs txt-secondary flex items-start gap-2'
                : 'text-sm txt-secondary flex items-start gap-2'
            "
          >
            <span class="text-yellow-500">•</span>
            {{ issue }}
          </li>
        </ul>
      </div>

      <!-- Recommendations -->
      <div v-if="summary.recommendations.length > 0" :class="compact ? 'mb-4' : 'mb-6'">
        <h4 class="text-sm font-medium txt-primary mb-2 flex items-center gap-1">
          <Icon icon="heroicons:light-bulb" class="w-4 h-4 text-green-500" />
          {{ $t('summary.recommendations') }}
        </h4>
        <ul class="space-y-1">
          <li
            v-for="rec in compact ? summary.recommendations.slice(0, 3) : summary.recommendations"
            :key="rec"
            :class="
              compact
                ? 'text-xs txt-secondary flex items-start gap-2'
                : 'text-sm txt-secondary flex items-start gap-2'
            "
          >
            <span class="text-green-500">•</span>
            {{ rec }}
          </li>
        </ul>
      </div>

      <!-- Prompt Suggestions (new section) -->
      <div
        v-if="summary.promptSuggestions && summary.promptSuggestions.length > 0"
        class="p-4 rounded-lg bg-purple-500/10 border border-purple-500/30"
      >
        <div class="flex items-center justify-between mb-3">
          <h4 class="text-sm font-medium txt-primary flex items-center gap-2">
            <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4 text-purple-500" />
            {{ $t('summary.promptSuggestions') }}
          </h4>
          <button
            class="px-3 py-1.5 rounded-lg text-xs font-medium bg-purple-500 text-white hover:bg-purple-600 transition-colors flex items-center gap-1.5"
            @click="openPromptEditor"
          >
            <Icon icon="heroicons:pencil-square" class="w-3.5 h-3.5" />
            {{ $t('summary.editPrompt') }}
          </button>
        </div>
        <div class="space-y-4">
          <div
            v-for="(suggestion, index) in summary.promptSuggestions"
            :key="index"
            class="border-l-2 pl-3"
            :class="suggestion.type === 'add' ? 'border-green-500' : 'border-blue-500'"
          >
            <div
              class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold uppercase mb-1.5"
              :class="
                suggestion.type === 'add'
                  ? 'bg-green-500/20 text-green-600'
                  : 'bg-blue-500/20 text-blue-600'
              "
            >
              <Icon
                :icon="suggestion.type === 'add' ? 'heroicons:plus-circle' : 'heroicons:arrow-path'"
                class="w-3.5 h-3.5 mr-1"
              />
              {{ suggestion.type === 'add' ? $t('summary.addInfo') : $t('summary.improveInfo') }}
            </div>
            <p class="txt-secondary text-sm leading-relaxed">{{ suggestion.suggestion }}</p>
          </div>
        </div>
      </div>
    </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import { useNotification } from '@/composables/useNotification'
import { useAuth } from '@/composables/useAuth'

const props = withDefaults(
  defineProps<{
    widgetId: string
    compact?: boolean
    selectedSessionIds?: string[]
  }>(),
  {
    compact: false,
    selectedSessionIds: () => [],
  }
)

const emit = defineEmits<{
  editPrompt: []
}>()

const router = useRouter()
const { t } = useI18n()
const { error, success } = useNotification()
const { isTeam } = useAuth()

// Existing summary found message
const existingSummaryFound = ref(false)

const goToUpgrade = () => {
  router.push({ name: 'subscription' })
}

const generating = ref(false)
const summary = ref<widgetSessionsApi.WidgetSummary | null>(null)
const savedSummaries = ref<widgetSessionsApi.WidgetSummary[]>([])
const loadingSummaries = ref(false)
const showSavedSummaries = ref(false)
const selectedSavedSummary = ref<widgetSessionsApi.WidgetSummary | null>(null)

// Date range for analysis
const fromDate = ref<string>('')
const toDate = ref<string>('')

// Set default dates (last 7 days)
const setDefaultDates = () => {
  const today = new Date()
  const weekAgo = new Date()
  weekAgo.setDate(weekAgo.getDate() - 7)

  toDate.value = today.toISOString().split('T')[0]
  fromDate.value = weekAgo.toISOString().split('T')[0]
}

// Convert date string to YYYYMMDD format
const dateToNumber = (dateStr: string): number | undefined => {
  if (!dateStr) return undefined
  return parseInt(dateStr.replace(/-/g, ''), 10)
}

// Format date from YYYYMMDD to readable string
const formatDateNumber = (date: number): string => {
  const str = String(date)
  const year = str.substring(0, 4)
  const month = str.substring(4, 6)
  const day = str.substring(6, 8)
  return `${day}.${month}.${year}`
}

// Format timestamp to readable date
const formatTimestamp = (timestamp: number): string => {
  return new Date(timestamp * 1000).toLocaleString()
}

// Load saved summaries
const loadSavedSummaries = async () => {
  loadingSummaries.value = true
  try {
    const response = await widgetSessionsApi.getWidgetSummaries(props.widgetId, 10)
    savedSummaries.value = response.summaries || []
  } catch (err: any) {
    console.error('Failed to load summaries:', err)
  } finally {
    loadingSummaries.value = false
  }
}

// Select a saved summary to view
const selectSavedSummary = (s: widgetSessionsApi.WidgetSummary) => {
  selectedSavedSummary.value = s
  summary.value = s
  showSavedSummaries.value = false
}

// Clear selected summary and go back to generator
const clearSummary = () => {
  summary.value = null
  selectedSavedSummary.value = null
}

const generateAnalysis = async (forceRegenerate = false) => {
  existingSummaryFound.value = false
  const regeneratingSummary = selectedSavedSummary.value

  // If not regenerating and not forcing, check for existing summary with same date range
  if (!regeneratingSummary && !forceRegenerate && props.selectedSessionIds.length === 0) {
    const fromNum = dateToNumber(fromDate.value)
    const toNum = dateToNumber(toDate.value)

    // Check if any saved summary has the exact same date range
    const existingSummary = savedSummaries.value.find(
      (s) => s.fromDate === fromNum && s.toDate === toNum
    )

    if (existingSummary) {
      // Found existing summary, use it instead of generating
      summary.value = existingSummary
      selectedSavedSummary.value = existingSummary
      existingSummaryFound.value = true
      success(t('summary.existingFound'))
      return
    }
  }

  generating.value = true
  summary.value = null

  try {
    const params: widgetSessionsApi.AnalyzeSummaryParams = {}

    // If regenerating a saved summary, use its parameters
    if (regeneratingSummary?.id) {
      params.summaryId = regeneratingSummary.id
      if (regeneratingSummary.fromDate) {
        params.fromDate = regeneratingSummary.fromDate
      }
      if (regeneratingSummary.toDate) {
        params.toDate = regeneratingSummary.toDate
      }
    } else if (props.selectedSessionIds.length > 0) {
      params.sessionIds = props.selectedSessionIds
    } else {
      if (fromDate.value) {
        params.fromDate = dateToNumber(fromDate.value)
      }
      if (toDate.value) {
        params.toDate = dateToNumber(toDate.value)
      }
    }

    const response = await widgetSessionsApi.analyzeWidgetSessions(props.widgetId, params)
    summary.value = response.summary
    selectedSavedSummary.value = response.summary
    success(t('summary.generateSuccess'))
    // Reload saved summaries to reflect the update
    await loadSavedSummaries()
  } catch (err: any) {
    error(err.message || 'Failed to generate analysis')
  } finally {
    generating.value = false
  }
}

// Reset summary when selected sessions change
watch(
  () => props.selectedSessionIds,
  () => {
    summary.value = null
    selectedSavedSummary.value = null
  },
  { deep: true }
)

// Initialize
onMounted(() => {
  setDefaultDates()
  loadSavedSummaries()
})

// Emit event to open prompt editor
const openPromptEditor = () => {
  emit('editPrompt')
}
</script>
