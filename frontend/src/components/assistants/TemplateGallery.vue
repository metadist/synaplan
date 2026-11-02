<template>
  <div data-testid="section-template-gallery">
    <div v-if="loading" class="surface-card rounded-xl p-12 text-center">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div
      v-else-if="loadError"
      class="surface-card rounded-xl p-8 text-center space-y-4"
      data-testid="state-templates-error"
    >
      <p class="txt-primary text-sm">{{ $t('templates.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-templates-retry"
        @click="load"
      >
        {{ $t('templates.retry') }}
      </button>
    </div>

    <TemplateDetail
      v-else-if="selected"
      :template="selected"
      :people-count="peopleCount"
      :publish-review-required="publishReviewRequired"
      @back="selectedId = null"
      @installed="onInstalled"
    />

    <template v-else>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <button
          v-for="chip in chips"
          :key="chip.id"
          type="button"
          class="px-4 py-2.5 rounded-full text-sm font-medium"
          :class="category === chip.id ? 'btn-primary' : 'btn-secondary'"
          :aria-pressed="category === chip.id"
          :data-testid="`chip-template-${chip.id}`"
          @click="category = chip.id"
        >
          {{ chip.label }}
        </button>
        <input
          v-model="search"
          type="search"
          class="ml-auto min-w-0 w-full sm:w-auto sm:min-w-[12rem] sm:flex-1 sm:max-w-xs px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('templates.searchPlaceholder')"
          data-testid="input-template-search"
        />
      </div>

      <p
        v-if="templates.length === 0"
        class="surface-card rounded-xl p-8 text-center txt-secondary text-sm mb-4"
        data-testid="state-templates-empty"
      >
        {{ $t('templates.empty') }}
      </p>
      <p
        v-else-if="visible.length === 0"
        class="surface-card rounded-xl p-8 text-center txt-secondary text-sm mb-4"
        data-testid="state-templates-no-results"
      >
        {{ $t('templates.noResults', { query: search.trim() }) }}
      </p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-testid="list-template-cards">
        <button
          v-for="template in visible"
          :key="template.id"
          type="button"
          class="btn-secondary rounded-xl p-5 text-left space-y-2 focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :data-testid="`card-template-${template.id}`"
          @click="selectedId = template.id ?? null"
        >
          <h3 class="txt-primary font-medium">{{ templateTitle(template, t) }}</h3>
          <p class="txt-secondary text-sm">{{ templateSummary(template, t) }}</p>
          <p
            v-if="!template.pluginName"
            class="txt-primary text-sm"
            :data-testid="`text-template-preview-${template.id}`"
          >
            {{ templatePreview(template, t) }}
          </p>
          <p
            v-if="template.pluginName"
            class="txt-secondary text-xs"
            :data-testid="`badge-from-plugin-${template.id}`"
          >
            {{ $t('templates.fromPlugin', { name: template.pluginName }) }}
          </p>
          <p v-else class="txt-secondary text-xs">
            {{
              (template.usedBy ?? 0) > 0
                ? $t('templates.usedBy', { count: template.usedBy ?? 0 })
                : $t('templates.usedByZero')
            }}
          </p>
        </button>
        <button
          type="button"
          class="btn-secondary rounded-xl p-5 text-left space-y-2 border-dashed focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="card-template-empty"
          @click="emit('start-empty')"
        >
          <h3 class="txt-primary font-medium">{{ $t('templates.startEmpty') }}</h3>
          <p class="txt-secondary text-sm">{{ $t('templates.startEmptyHint') }}</p>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import TemplateDetail from './TemplateDetail.vue'
import {
  agentTemplatesApi,
  templateSummary,
  templateTitle,
  type AgentTemplate,
} from '@/services/api/agentTemplatesApi'
import { templatePreview } from './templatePreview'

const emit = defineEmits<{
  'start-empty': []
  installed: [id: number]
}>()

const { t } = useI18n()
const loading = ref(true)
const loadError = ref(false)
const templates = ref<AgentTemplate[]>([])
const peopleCount = ref<number | null>(null)
const publishReviewRequired = ref(false)
const search = ref('')
const category = ref('all')
const selectedId = ref<string | null>(null)

const selected = computed(
  () => templates.value.find((template) => template.id === selectedId.value) ?? null
)

const chips = computed(() => {
  const ids = [...new Set(templates.value.map((template) => template.category).filter(Boolean))]
  return [
    { id: 'all', label: t('templates.all') },
    ...ids.map((id) => ({ id: String(id), label: t(`templates.category.${id}`) })),
  ]
})

const visible = computed(() => {
  const query = search.value.trim().toLowerCase()
  return templates.value.filter((template) => {
    if (category.value !== 'all' && template.category !== category.value) return false
    if (!query) return true
    const title = templateTitle(template, t).toLowerCase()
    const summary = templateSummary(template, t).toLowerCase()
    const plugin = (template.pluginName ?? '').toLowerCase()
    const previewText = template.pluginName ? '' : templatePreview(template, t).toLowerCase()
    return (
      title.includes(query) ||
      summary.includes(query) ||
      plugin.includes(query) ||
      previewText.includes(query)
    )
  })
})

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const data = await agentTemplatesApi.list()
    templates.value = data.templates
    peopleCount.value = data.peopleCount
    publishReviewRequired.value = data.publishReviewRequired
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function onInstalled(id: number): void {
  emit('installed', id)
}

onMounted(() => {
  void load()
})
</script>
