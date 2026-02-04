<template>
  <div :class="compact ? '' : 'surface-card p-4 lg:p-6'">
    <!-- Header -->
    <div v-if="!compact" class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
        <Icon icon="heroicons:chart-bar" class="w-5 h-5 txt-brand" />
        {{ $t('summary.title') }}
      </h3>
      <div class="flex items-center gap-2">
        <select
          v-model="selectedDate"
          class="px-3 py-1.5 rounded-lg surface-chip text-sm txt-primary"
          @change="loadSummary"
        >
          <option v-for="option in dateOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <button
          :disabled="generating"
          class="px-3 py-1.5 rounded-lg btn-primary text-xs font-medium flex items-center gap-1 disabled:opacity-50"
          @click="generateSummary"
        >
          <Icon
            :icon="generating ? 'heroicons:arrow-path' : 'heroicons:sparkles'"
            :class="['w-4 h-4', generating && 'animate-spin']"
          />
          {{ generating ? $t('summary.generating') : $t('summary.generate') }}
        </button>
      </div>
    </div>

    <!-- Compact Header -->
    <div v-else class="flex items-center gap-2 mb-3">
      <select
        v-model="selectedDate"
        class="flex-1 px-2 py-1.5 rounded-lg surface-chip text-xs txt-primary"
        @change="loadSummary"
      >
        <option v-for="option in dateOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
      <button
        :disabled="generating"
        class="px-2 py-1.5 rounded-lg btn-primary text-xs font-medium flex items-center gap-1 disabled:opacity-50"
        @click="generateSummary"
      >
        <Icon
          :icon="generating ? 'heroicons:arrow-path' : 'heroicons:sparkles'"
          :class="['w-3.5 h-3.5', generating && 'animate-spin']"
        />
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-8">
      <div
        class="animate-spin w-8 h-8 border-4 border-[var(--brand)] border-t-transparent rounded-full mx-auto mb-4"
      ></div>
      <p class="txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>

    <!-- No Summary -->
    <div v-else-if="!summary" class="text-center py-8">
      <Icon
        icon="heroicons:document-chart-bar"
        class="w-12 h-12 txt-secondary opacity-30 mx-auto mb-4"
      />
      <p class="txt-secondary text-sm">{{ $t('summary.noSummary') }}</p>
      <button class="mt-4 px-4 py-2 rounded-lg btn-primary text-sm" @click="generateSummary">
        {{ $t('summary.generateFirst') }}
      </button>
    </div>

    <!-- Summary Content -->
    <template v-else>
      <!-- Stats Row -->
      <div
        :class="
          compact ? 'grid grid-cols-2 gap-2 mb-4' : 'grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6'
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
        <div
          :class="
            compact
              ? 'p-2 surface-chip rounded-lg text-center'
              : 'p-3 surface-chip rounded-lg text-center'
          "
        >
          <p class="text-xs txt-secondary">{{ $t('summary.positive') }}</p>
          <p
            :class="
              compact ? 'text-lg font-bold text-green-600' : 'text-xl font-bold text-green-600'
            "
          >
            {{ summary.sentiment.positive }}%
          </p>
        </div>
        <div
          :class="
            compact
              ? 'p-2 surface-chip rounded-lg text-center'
              : 'p-3 surface-chip rounded-lg text-center'
          "
        >
          <p class="text-xs txt-secondary">{{ $t('summary.negative') }}</p>
          <p :class="compact ? 'text-lg font-bold text-red-600' : 'text-xl font-bold text-red-600'">
            {{ summary.sentiment.negative }}%
          </p>
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
      <div v-if="summary.recommendations.length > 0">
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
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import { useNotification } from '@/composables/useNotification'

const props = withDefaults(
  defineProps<{
    widgetId: string
    compact?: boolean
  }>(),
  {
    compact: false,
  }
)

const { t } = useI18n()
const { error, success } = useNotification()

const loading = ref(false)
const generating = ref(false)
const summary = ref<widgetSessionsApi.WidgetSummary | null>(null)
const selectedDate = ref<number>(0)

const dateOptions = computed(() => {
  const options = []
  for (let i = 1; i <= 7; i++) {
    const date = new Date()
    date.setDate(date.getDate() - i)
    const dateValue = parseInt(
      `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}`,
      10
    )
    options.push({
      value: dateValue,
      label: i === 1 ? t('summary.yesterday') : date.toLocaleDateString(),
    })
  }
  return options
})

const loadSummary = async () => {
  if (!selectedDate.value) return

  loading.value = true
  try {
    const response = await widgetSessionsApi.getWidgetSummaryByDate(
      props.widgetId,
      selectedDate.value
    )
    summary.value = response.summary
  } catch (err: any) {
    // Summary might not exist yet
    summary.value = null
  } finally {
    loading.value = false
  }
}

const generateSummary = async () => {
  generating.value = true
  try {
    const date = selectedDate.value || dateOptions.value[0]?.value
    const response = await widgetSessionsApi.generateWidgetSummary(props.widgetId, date)
    summary.value = response.summary
    success(t('summary.generateSuccess'))
  } catch (err: any) {
    error(err.message || 'Failed to generate summary')
  } finally {
    generating.value = false
  }
}

onMounted(() => {
  // Default to yesterday
  if (dateOptions.value.length > 0) {
    selectedDate.value = dateOptions.value[0].value
    loadSummary()
  }
})
</script>
