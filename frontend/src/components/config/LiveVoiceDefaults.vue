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
          <div
            v-if="showModelFilter"
            class="shrink-0 border-b border-light-border/30 px-2 pb-3 pt-2 dark:border-dark-border/20"
            data-testid="section-live-voice-model-filter"
          >
            <label class="sr-only" for="live-voice-model-filter">
              {{ t('config.aiModels.dropdownFilterLabel') }}
            </label>
            <div class="relative">
              <MagnifyingGlassIcon
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 txt-secondary"
                aria-hidden="true"
              />
              <input
                id="live-voice-model-filter"
                ref="modelFilterInput"
                v-model="modelFilter"
                type="text"
                autocomplete="off"
                spellcheck="false"
                enterkeyhint="go"
                class="w-full rounded-xl surface-card border border-light-border/30 py-1.5 pl-9 pr-9 text-sm txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] dark:border-dark-border/20"
                :placeholder="t('config.aiModels.dropdownFilterPlaceholder')"
                data-testid="input-live-voice-model-filter"
                @keydown.enter="onModelFilterEnter"
                @keydown.escape.stop.prevent="onModelFilterEscape"
              />
              <button
                v-if="modelFilter"
                type="button"
                class="icon-ghost absolute right-1.5 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md"
                :aria-label="t('config.aiModels.dropdownClearFilter')"
                data-testid="btn-live-voice-model-filter-clear"
                @click="clearModelFilter"
              >
                <XMarkIcon class="h-3.5 w-3.5" aria-hidden="true" />
              </button>
            </div>
          </div>
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
          <span class="block truncate capitalize">{{
            voices.length > 0 ? voice : t('settings.voice.noVoices')
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
          <div class="min-h-0 overflow-y-auto">
            <button
              v-for="name in voices"
              :key="name"
              type="button"
              role="option"
              :aria-selected="name === voice"
              :class="['dropdown-item w-full', name === voice && 'dropdown-item--active']"
              data-testid="btn-live-voice-voice-option"
              @click="chooseVoice(name)"
            >
              <SpeakerWaveIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
              <span class="min-w-0 flex-1 truncate text-left font-medium capitalize">{{
                name
              }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ChevronDownIcon,
  CpuChipIcon,
  MagnifyingGlassIcon,
  SpeakerWaveIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import type { LiveVoiceModel } from '@/services/api/liveVoiceApi'
import { voiceCompany } from '@/services/liveVoice/company'
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

const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
const modelFilterInput = ref<HTMLInputElement | null>(null)
const menu = ref<'model' | 'voice' | null>(null)
const modelFilter = ref('')

const selectedModel = computed(
  () => props.models.find((model) => model.id === props.modelId) ?? null
)
const voices = computed(() => selectedModel.value?.voices ?? [])
const showModelFilter = computed(() => props.models.length >= FILTER_MIN)

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
    void nextTick(() => modelFilterInput.value?.focus())
  }
}

function onModelFilterEnter(event: KeyboardEvent) {
  if (event.isComposing || event.keyCode === 229) return
  event.preventDefault()
  const first = visibleModels.value[0]
  if (first) chooseModel(first)
}

function onModelFilterEscape() {
  if (modelFilter.value) {
    modelFilter.value = ''
    modelFilterInput.value?.focus()
    return
  }
  menu.value = null
}

function clearModelFilter() {
  modelFilter.value = ''
  modelFilterInput.value?.focus()
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
