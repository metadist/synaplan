<template>
  <div
    ref="root"
    class="grid grid-cols-1 gap-5 sm:grid-cols-2"
    data-testid="section-live-voice-fields"
  >
    <div class="space-y-2" :class="menu === 'model' ? 'z-10' : 'z-0'">
      <p
        id="live-voice-model-label"
        class="flex items-center gap-2 text-sm font-semibold txt-primary"
      >
        <CpuChipIcon class="h-4 w-4 text-[var(--brand)]" aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ t('settings.voice.model') }}</span>
      </p>
      <div class="relative">
        <button
          type="button"
          :class="triggerClass(menu === 'model')"
          :disabled="locked || saving"
          aria-haspopup="listbox"
          :aria-expanded="menu === 'model'"
          aria-controls="live-voice-model-menu"
          aria-labelledby="live-voice-model-label"
          data-testid="select-live-voice-model"
          @click="toggle('model')"
        >
          <span class="flex items-center gap-2 truncate">
            <span class="truncate">{{
              selectedModel?.name || t('config.aiModels.selectModel')
            }}</span>
            <ModelCostBadge v-if="selectedPriced" :model="selectedPriced" :peers="pricedModels" />
          </span>
        </button>
        <div class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2">
          <ServiceIcon :service="selectedModel?.provider ?? ''" :size="16" />
        </div>
        <ChevronDownIcon :class="chevronClass(menu === 'model')" aria-hidden="true" />
        <div
          v-if="menu === 'model'"
          id="live-voice-model-menu"
          class="absolute z-50 mt-2 flex max-h-[60vh] w-full flex-col dropdown-panel"
          role="listbox"
          aria-labelledby="live-voice-model-label"
        >
          <LiveVoiceMenuFilter
            v-if="showModelFilter"
            ref="modelFilterField"
            v-model="modelFilter"
            test-id="live-voice-model-filter"
            :label="t('config.aiModels.dropdownFilterLabel')"
            :placeholder="t('config.aiModels.dropdownFilterPlaceholder')"
            @submit="chooseFirstModel"
            @escape="onModelFilterEscape"
          />
          <div class="flex min-h-0 flex-col gap-1 overflow-y-auto pt-2">
            <button
              v-for="model in visibleModels"
              :key="model.id ?? model.name"
              type="button"
              role="option"
              :aria-selected="model.id === modelId"
              :class="['dropdown-item w-full', model.id === modelId && 'dropdown-item--active']"
              data-testid="btn-live-voice-model-option"
              @click="chooseModel(model)"
            >
              <ServiceIcon :service="model.provider ?? ''" :size="20" />
              <span class="min-w-0 flex-1 text-left">
                <span class="flex items-center gap-2">
                  <span class="truncate font-medium">{{ model.name }}</span>
                  <ModelCostBadge
                    v-if="pricedById.get(model.id ?? -1)"
                    :model="pricedById.get(model.id ?? -1)!"
                    :peers="pricedModels"
                  />
                </span>
                <span class="block truncate text-xs txt-secondary">{{
                  voiceCompany(model.provider ?? '')
                }}</span>
              </span>
            </button>
            <p
              v-if="modelFilter.trim() && visibleModels.length === 0"
              class="px-3 py-2 text-sm txt-secondary"
              data-testid="text-live-voice-model-filter-empty"
            >
              {{ t('config.aiModels.dropdownNoMatch', { query: modelFilter.trim() }) }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="space-y-2" :class="menu === 'voice' ? 'z-10' : 'z-0'">
      <p
        id="live-voice-voice-label"
        class="flex items-center gap-2 text-sm font-semibold txt-primary"
      >
        <CpuChipIcon class="h-4 w-4 text-[var(--brand)]" aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ t('settings.voice.voice') }}</span>
      </p>
      <div class="relative">
        <button
          type="button"
          :class="triggerClass(menu === 'voice')"
          :disabled="saving || voices.length === 0"
          aria-haspopup="listbox"
          :aria-expanded="menu === 'voice'"
          aria-controls="live-voice-voice-menu"
          aria-labelledby="live-voice-voice-label"
          data-testid="select-live-voice-voice"
          @click="toggle('voice')"
        >
          <span class="block truncate">{{
            voices.length > 0 ? selectedVoiceName : t('settings.voice.noVoices')
          }}</span>
        </button>
        <SpeakerWaveIcon
          class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 txt-secondary"
          aria-hidden="true"
        />
        <ChevronDownIcon :class="chevronClass(menu === 'voice')" aria-hidden="true" />
        <div
          v-if="menu === 'voice'"
          id="live-voice-voice-menu"
          class="absolute z-50 mt-2 flex max-h-[60vh] w-full flex-col dropdown-panel"
          role="listbox"
          aria-labelledby="live-voice-voice-label"
        >
          <LiveVoiceMenuFilter
            v-if="showVoiceFilter"
            ref="voiceFilterField"
            v-model="voiceFilter"
            test-id="live-voice-voice-filter"
            :label="t('settings.voice.filterLabel')"
            :placeholder="t('settings.voice.filterPlaceholder')"
            @submit="chooseFirstVoice"
            @escape="onVoiceFilterEscape"
          />
          <div class="flex min-h-0 flex-col gap-1 overflow-y-auto pt-2">
            <button
              v-for="row in visibleVoices"
              :key="row.id"
              type="button"
              role="option"
              :aria-selected="row.id === voice"
              :class="['dropdown-item w-full', row.id === voice && 'dropdown-item--active']"
              data-testid="btn-live-voice-voice-option"
              @click="chooseVoice(row.id)"
            >
              <SpeakerWaveIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
              <span class="min-w-0 flex-1 text-left">
                <span class="block truncate font-medium">{{ row.name }}</span>
                <span v-if="row.tone" class="block truncate text-xs txt-secondary">{{
                  row.tone
                }}</span>
              </span>
            </button>
            <p
              v-if="voiceFilter.trim() && visibleVoices.length === 0"
              class="px-3 py-2 text-sm txt-secondary"
              data-testid="text-live-voice-voice-filter-empty"
            >
              {{ t('settings.voice.filterEmpty', { query: voiceFilter.trim() }) }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon, CpuChipIcon, SpeakerWaveIcon } from '@heroicons/vue/24/outline'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import LiveVoiceMenuFilter from '@/components/config/LiveVoiceMenuFilter.vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import type { LiveVoiceModel } from '@/services/api/liveVoiceApi'
import { voiceCompany } from '@/services/liveVoice/company'
import { voiceCopy } from '@/services/liveVoice/voiceCopy'
import type { AIModel } from '@/types/ai-models'

const props = defineProps<{
  models: LiveVoiceModel[]
  modelId: number | null
  voice: string
  locked: boolean
  saving: boolean
  /** Bumped by the parent when another menu on the page opens. */
  dismiss: number
}>()

const emit = defineEmits<{
  'update:modelId': [id: number]
  'update:voice': [voice: string]
  menu: [open: boolean]
  modelCommit: []
  voiceCommit: []
}>()

/** Same cutoff as the other model menus: a short list is faster to scan than to filter. */
const FILTER_MIN = 6

const { t, te } = useI18n()
const root = ref<HTMLElement | null>(null)
const modelFilterField = ref<{ focus: () => void } | null>(null)
const voiceFilterField = ref<{ focus: () => void } | null>(null)
const menu = ref<'model' | 'voice' | null>(null)
const modelFilter = ref('')
const voiceFilter = ref('')

const selectedModel = computed(
  () => props.models.find((model) => model.id === props.modelId) ?? null
)
const voices = computed(() => selectedModel.value?.voices ?? [])
const showModelFilter = computed(() => props.models.length >= FILTER_MIN)
const showVoiceFilter = computed(() => voices.value.length >= FILTER_MIN)
const selectedVoiceName = computed(() => {
  const id = props.voice
  if (id === '') return ''
  return voiceCopy(t, te, selectedModel.value?.provider ?? '', id).name
})

const pricedModels = computed(() =>
  props.models.flatMap((model) => {
    const priced = toPricedModel(model)
    return priced ? [priced] : []
  })
)
const pricedById = computed(() => new Map(pricedModels.value.map((model) => [model.id, model])))
const selectedPriced = computed(() =>
  selectedModel.value?.id == null ? null : (pricedById.value.get(selectedModel.value.id) ?? null)
)

const modelFilterTerms = computed(() => normalize(modelFilter.value).split(/\s+/).filter(Boolean))
const voiceRows = computed(() => {
  const provider = selectedModel.value?.provider ?? ''
  return voices.value.map((id) => ({ id, ...voiceCopy(t, te, provider, id) }))
})
const visibleVoices = computed(() => {
  const sorted = [...voiceRows.value].sort((a, b) =>
    a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
  )
  const terms = normalize(voiceFilter.value).split(/\s+/).filter(Boolean)
  if (terms.length === 0) return sorted
  return sorted.filter((row) => {
    const text = normalize(`${row.name} ${row.tone} ${row.id}`)
    return terms.every((term) => text.includes(term))
  })
})
const visibleModels = computed(() => {
  const sorted = [...props.models].sort((a, b) =>
    (a.name ?? '').localeCompare(b.name ?? '', undefined, { sensitivity: 'base' })
  )
  const terms = modelFilterTerms.value
  if (terms.length === 0) return sorted
  return sorted.filter((model) => {
    const text = normalize(
      `${model.name ?? ''} ${model.provider ?? ''} ${voiceCompany(model.provider ?? '')}`
    )
    return terms.every((term) => text.includes(term))
  })
})

watch(menu, (value) => {
  emit('menu', value !== null)
  if (value !== 'model') modelFilter.value = ''
  if (value !== 'voice') voiceFilter.value = ''
})
watch(
  () => props.dismiss,
  () => {
    menu.value = null
  }
)

function triggerClass(open: boolean): string[] {
  return [
    'w-full px-4 py-3 pl-10 pr-10 rounded-xl surface-card border txt-primary text-sm text-left focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all',
    'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
    open ? 'ring-2 ring-[var(--brand)]' : '',
    'disabled:opacity-60 disabled:cursor-not-allowed disabled:hover:border-light-border/30 dark:disabled:hover:border-dark-border/20',
  ]
}

function chevronClass(open: boolean): string[] {
  return [
    'pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 txt-secondary transition-transform',
    open ? 'rotate-180' : '',
  ]
}

function normalize(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

function toPricedModel(model: LiveVoiceModel): AIModel | null {
  if (typeof model.priceIn !== 'number' || model.id == null) return null
  return {
    id: model.id,
    service: voiceCompany(model.provider ?? ''),
    name: model.name ?? '',
    tag: 'voice2voice',
    providerId: model.provider ?? '',
    quality: 0,
    rating: 0,
    priceIn: model.priceIn,
    priceOut: model.priceOut ?? 0,
    priceKnown: model.priceKnown !== false,
    description: null,
    isSystemModel: false,
    features: [],
  }
}

function toggle(which: 'model' | 'voice') {
  if (which === 'model' && (props.locked || props.saving)) return
  if (which === 'voice' && (props.saving || voices.value.length === 0)) return
  menu.value = menu.value === which ? null : which
  if (menu.value === 'model' && showModelFilter.value) {
    void nextTick(() => modelFilterField.value?.focus())
  }
  if (menu.value === 'voice' && showVoiceFilter.value) {
    void nextTick(() => voiceFilterField.value?.focus())
  }
}

function chooseFirstModel() {
  const first = visibleModels.value[0]
  if (first) chooseModel(first)
}

function onModelFilterEscape() {
  if (modelFilter.value) {
    modelFilter.value = ''
    modelFilterField.value?.focus()
    return
  }
  menu.value = null
}

function chooseFirstVoice() {
  const first = visibleVoices.value[0]
  if (first) chooseVoice(first.id)
}

function onVoiceFilterEscape() {
  if (voiceFilter.value) {
    voiceFilter.value = ''
    voiceFilterField.value?.focus()
    return
  }
  menu.value = null
}

function chooseModel(model: LiveVoiceModel) {
  if (model.id == null) return
  menu.value = null
  emit('update:modelId', model.id)
  emit('modelCommit')
}

function chooseVoice(name: string) {
  menu.value = null
  emit('update:voice', name)
  emit('voiceCommit')
}

function onDocumentClick(event: MouseEvent) {
  if (!menu.value) return
  const target = event.target
  if (target instanceof Node && root.value?.contains(target)) return
  menu.value = null
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && menu.value) menu.value = null
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>
