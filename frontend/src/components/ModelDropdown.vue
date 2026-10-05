<template>
  <div ref="dropdownRef" class="relative min-w-0 max-w-full" data-testid="comp-model-dropdown">
    <button
      ref="triggerRef"
      type="button"
      :class="['pill h-11 min-w-0 max-w-full overflow-hidden px-2.5', isOpen && 'pill--active']"
      :aria-label="ariaLabel"
      :title="ariaLabel"
      :aria-haspopup="guest ? undefined : 'listbox'"
      :aria-controls="isOpen ? panelId : undefined"
      :aria-expanded="guest ? undefined : isOpen"
      data-testid="btn-model-toggle"
      @click="onTriggerClick"
      @keydown.down.prevent="openFromKeyboard"
      @keydown.up.prevent="openFromKeyboard"
      @keydown.escape.stop="closeAndRestoreFocus"
    >
      <ServiceIcon
        v-if="effectiveModel"
        :service="effectiveModel.service"
        :size="18"
        class="flex-shrink-0"
      />
      <Icon v-else icon="mdi:robot-outline" class="h-4 w-4 flex-shrink-0" />
      <span class="min-w-0 truncate text-xs font-medium" data-testid="model-chip-name">
        {{ triggerModelName }}
      </span>
      <span
        v-if="currentLevelLabel"
        class="hidden max-w-[5.5rem] flex-shrink-0 truncate text-xs txt-secondary sm:inline"
        data-testid="reasoning-level-current"
      >
        · {{ currentLevelLabel }}
      </span>
      <ChevronUpIcon class="h-4 w-4 flex-shrink-0" />
    </button>

    <div
      v-if="isOpen"
      :id="panelId"
      class="dropdown-up left-auto right-0 flex origin-bottom-right flex-col"
      :style="panelStyle"
      data-testid="dropdown-model-panel"
      @keydown.escape.stop.prevent="closeAndRestoreFocus"
    >
      <div v-if="showFilter" class="model-filter" data-testid="section-model-filter">
        <label class="sr-only" :for="filterId">{{
          $t('chatInput.modelDropdown.filterLabel')
        }}</label>
        <span class="model-filter__icon txt-secondary" aria-hidden="true">
          <MagnifyingGlassIcon class="h-4 w-4" />
        </span>
        <input
          :id="filterId"
          ref="filterRef"
          v-model="filterQuery"
          type="text"
          autocomplete="off"
          spellcheck="false"
          enterkeyhint="go"
          class="w-full rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-[13px] py-1.5 pl-8 pr-8 focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('chatInput.modelDropdown.filterPlaceholder')"
          :aria-controls="listboxId"
          data-testid="input-model-filter"
          @keydown.down.prevent="focusFirstOption"
          @keydown.enter.prevent="pickFirstMatch"
          @keydown.escape.stop.prevent="onFilterEscape"
        />
        <button
          v-if="filterQuery"
          type="button"
          class="model-filter__clear icon-ghost inline-flex h-6 w-6 items-center justify-center rounded-md"
          :aria-label="$t('chatInput.modelDropdown.clearFilter')"
          data-testid="btn-model-filter-clear"
          @click="clearFilter"
        >
          <XMarkIcon class="h-3.5 w-3.5" aria-hidden="true" />
        </button>
      </div>

      <div
        :id="listboxId"
        ref="listRef"
        class="max-h-[min(18rem,42vh)] min-h-0 overflow-y-auto scroll-thin"
        role="listbox"
        :aria-label="$t('chatInput.model')"
        @keydown="onListKeydown"
      >
        <button
          v-if="showDefault"
          ref="defaultRef"
          :class="['dropdown-item model-option', modelValue === null && 'dropdown-item--active']"
          type="button"
          role="option"
          :aria-selected="modelValue === null"
          data-testid="btn-model-default"
          @click="selectModel(null)"
          @keydown.down.prevent="focusNext"
          @keydown.up.prevent="onOptionUp"
        >
          <Icon icon="mdi:robot-outline" class="h-[18px] w-[18px] flex-shrink-0" />
          <span class="min-w-0 flex-1">
            <span class="model-option__name block truncate">
              {{ $t('chatInput.modelDropdown.default') }}
            </span>
            <span class="model-option__meta block truncate txt-secondary">
              {{ defaultModelName }}
            </span>
          </span>
          <CheckIcon v-if="modelValue === null" class="h-4 w-4 flex-shrink-0 text-[var(--brand)]" />
        </button>

        <button
          v-for="model in visibleModels"
          :key="model.id"
          ref="modelRefs"
          :class="[
            'dropdown-item model-option',
            modelValue === model.id && 'dropdown-item--active',
          ]"
          type="button"
          role="option"
          :aria-selected="modelValue === model.id"
          :data-testid="`btn-model-${model.id}`"
          @click="selectModel(model.id)"
          @keydown.down.prevent="focusNext"
          @keydown.up.prevent="onOptionUp"
        >
          <ServiceIcon :service="model.service" :size="18" class="flex-shrink-0" />
          <span class="min-w-0 flex-1">
            <span class="flex min-w-0 items-center gap-1.5">
              <span class="model-option__name min-w-0 truncate">{{ model.name }}</span>
              <ModelCostBadge class="flex-shrink-0" :model="model" :peers="chatModels" />
            </span>
            <span class="model-option__meta block truncate txt-secondary">{{ model.service }}</span>
          </span>
          <CheckIcon
            v-if="modelValue === model.id"
            class="h-4 w-4 flex-shrink-0 text-[var(--brand)]"
          />
        </button>

        <p
          v-if="noMatches"
          class="px-3 py-5 text-center text-[13px] txt-secondary"
          role="status"
          data-testid="text-model-filter-empty"
        >
          {{ $t('chatInput.modelDropdown.noMatch', { query: filterQuery.trim() }) }}
        </p>
      </div>

      <ReasoningLevelOptions
        v-if="levels.length > 0"
        :levels="levels"
        :model-value="reasoningEffort"
        @update:model-value="selectLevel"
        @close="closeAndRestoreFocus"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { useModelListKeyboard } from '@/composables/useModelListKeyboard'
import { CheckIcon, ChevronUpIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useAiConfigStore } from '@/stores/aiConfig'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import ReasoningLevelOptions from '@/components/ReasoningLevelOptions.vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import type { AIModel } from '@/types/ai-models'
import { modelPickerPanelStyle } from '@/utils/modelPickerPanel'

const props = withDefaults(
  defineProps<{
    modelValue: number | null
    levels?: string[]
    reasoningEffort?: string
    guest?: boolean
  }>(),
  {
    levels: () => [],
    reasoningEffort: '',
    guest: false,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  'update:reasoningEffort': [value: string]
  gate: []
}>()

/** Short lists scan faster than they filter; the field appears from here. */
const MODEL_FILTER_MIN = 6

const { t } = useI18n()
const aiConfigStore = useAiConfigStore()
const panelId = useId()
const filterId = useId()
const listboxId = useId()
const isOpen = ref(false)
const filterQuery = ref('')
const filterRef = ref<HTMLInputElement | null>(null)
const listRef = ref<HTMLElement | null>(null)
const defaultRef = ref<HTMLElement | null>(null)
const modelRefs = ref<HTMLElement[]>([])
const dropdownRef = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLElement | null>(null)
const panelStyle = ref<{ width: string; right: string }>({
  width: 'min(20rem, calc(100vw - 1.5rem))',
  right: '0px',
})

const chatModels = computed(() => {
  const models = aiConfigStore.models.CHAT || []
  return [...models]
    .filter((model) => model.service !== 'test')
    .sort((a, b) => a.name.localeCompare(b.name))
})

const defaultModelId = computed(() => aiConfigStore.defaults.CHAT)

const defaultModel = computed(
  () => chatModels.value.find((model) => model.id === defaultModelId.value) ?? null
)

const selectedModel = computed((): AIModel | null => {
  if (props.modelValue === null) return null
  return chatModels.value.find((model) => model.id === props.modelValue) ?? null
})

const effectiveModel = computed(() => selectedModel.value ?? defaultModel.value)

const defaultModelName = computed(
  () => defaultModel.value?.name ?? t('chatInput.modelDropdown.default')
)

const triggerModelName = computed(
  () => effectiveModel.value?.name ?? t('chatInput.modelDropdown.default')
)

/** Case- and accent-insensitive, so "gemini" finds "Gemini" and "über" finds "uber". */
const normalize = (value: string): string =>
  value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()

const filterTerms = computed(() => normalize(filterQuery.value).split(/\s+/).filter(Boolean))

const matchesFilter = (haystack: string): boolean => {
  if (filterTerms.value.length === 0) return true
  const text = normalize(haystack)
  return filterTerms.value.every((term) => text.includes(term))
}

const showFilter = computed(() => chatModels.value.length >= MODEL_FILTER_MIN)

const visibleModels = computed(() =>
  chatModels.value.filter((model) =>
    matchesFilter(`${model.name} ${model.service} ${model.providerId ?? ''}`)
  )
)

const showDefault = computed(() =>
  matchesFilter(`${t('chatInput.modelDropdown.default')} ${defaultModelName.value}`)
)

const noMatches = computed(() => !showDefault.value && visibleModels.value.length === 0)

const currentLevelLabel = computed(() =>
  props.reasoningEffort ? t(`chatInput.reasoningLevel.${props.reasoningEffort}`) : ''
)

const ariaLabel = computed(() =>
  currentLevelLabel.value
    ? t('chatInput.modelPicker.ariaLabel', {
        model: triggerModelName.value,
        level: currentLevelLabel.value,
      })
    : t('chatInput.modelPicker.ariaLabelNoLevel', { model: triggerModelName.value })
)

const placePanel = () => {
  const trigger = triggerRef.value
  if (!trigger) return
  const next = modelPickerPanelStyle(trigger)
  if (next) panelStyle.value = next
}

const { focusNext, focusPrevious, onTypeaheadKeydown, resetTypeahead } = useModelListKeyboard({
  defaultRef,
  modelRefs,
  labels: () => [
    ...(showDefault.value ? [t('chatInput.modelDropdown.default')] : []),
    ...visibleModels.value.map((model) => model.name),
  ],
})

const options = (): HTMLElement[] =>
  Array.from(listRef.value?.querySelectorAll<HTMLElement>('[role="option"]') ?? [])

const focusOption = (el: HTMLElement | undefined) => {
  if (!el) return
  el.focus()
  el.scrollIntoView({ block: 'nearest' })
}

const selectedOption = (): HTMLElement | undefined =>
  options().find((el) => el.getAttribute('aria-selected') === 'true')

const focusSelectedModel = async () => {
  await nextTick()
  focusOption(selectedOption() ?? options()[0])
}

const focusFirstOption = () => focusOption(options()[0])

/** Typing goes to the filter; a mouse user also lands there on open. */
const finePointer = () =>
  typeof window.matchMedia === 'function' &&
  window.matchMedia('(hover: hover) and (pointer: fine)').matches

const focusFilter = async () => {
  await nextTick()
  selectedOption()?.scrollIntoView({ block: 'nearest' })
  filterRef.value?.focus()
}

const openMenu = async (fromKeyboard = false) => {
  filterQuery.value = ''
  isOpen.value = true
  await nextTick()
  placePanel()
  if (showFilter.value && (fromKeyboard || finePointer())) {
    await focusFilter()
    return
  }
  await focusSelectedModel()
}

const closeMenu = () => {
  if (!isOpen.value) return
  isOpen.value = false
  filterQuery.value = ''
  resetTypeahead()
}

const clearFilter = () => {
  filterQuery.value = ''
  filterRef.value?.focus()
}

const onFilterEscape = () => {
  if (filterQuery.value) {
    filterQuery.value = ''
    return
  }
  closeAndRestoreFocus()
}

/** Enter in the filter takes the best match, so "son⏎" picks Sonnet. */
const pickFirstMatch = () => {
  if (filterTerms.value.length === 0) {
    void focusSelectedModel()
    return
  }
  const first = visibleModels.value[0]
  if (first) selectModel(first.id)
  else if (showDefault.value) selectModel(null)
}

const onOptionUp = () => {
  if (showFilter.value && document.activeElement === options()[0]) {
    filterRef.value?.focus()
    return
  }
  focusPrevious()
}

const onListKeydown = (event: KeyboardEvent) => {
  const printable =
    event.key.length === 1 &&
    event.key !== ' ' &&
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey &&
    !event.isComposing
  if (showFilter.value && printable) {
    event.preventDefault()
    filterQuery.value += event.key
    filterRef.value?.focus()
    return
  }
  onTypeaheadKeydown(event)
}

const closeAndRestoreFocus = () => {
  if (!isOpen.value) return
  closeMenu()
  void nextTick(() => triggerRef.value?.focus())
}

const onTriggerClick = () => {
  triggerHapticImpact('light')
  if (props.guest) {
    emit('gate')
    return
  }
  if (isOpen.value) {
    closeAndRestoreFocus()
    return
  }
  void openMenu()
}

const openFromKeyboard = () => {
  if (props.guest) {
    emit('gate')
    return
  }
  if (!isOpen.value) {
    void openMenu(true)
    return
  }
  void focusSelectedModel()
}

const selectModel = (modelId: number | null) => {
  emit('update:modelValue', modelId)
  closeAndRestoreFocus()
}

const selectLevel = (level: string) => {
  emit('update:reasoningEffort', level)
}

const handleClickOutside = (event: MouseEvent) => {
  if (!isOpen.value) return
  const target = event.target as HTMLElement
  if (dropdownRef.value?.contains(target)) return
  closeMenu()
}

const onResize = () => isOpen.value && placePanel()

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  window.removeEventListener('resize', onResize)
})
</script>

<style scoped>
.model-filter {
  position: relative;
  margin: 0 0 0.375rem;
}
.model-filter__icon {
  position: absolute;
  top: 50%;
  left: 0.625rem;
  z-index: 1;
  display: flex;
  transform: translateY(-50%);
  pointer-events: none;
}
.model-filter__clear {
  position: absolute;
  top: 50%;
  right: 0.375rem;
  transform: translateY(-50%);
}

/* Two compact lines instead of the generic menu row: name with its cost tier,
   provider underneath. About 42px a row instead of 56px. */
.model-option {
  gap: 0.625rem;
  padding: 0.3125rem 0.625rem;
  border-radius: 0.625rem;
}
.model-option + .model-option {
  margin-top: 1px;
}
.model-option__name {
  font-size: 0.8125rem;
  font-weight: 500;
  line-height: 1.125rem;
}
.model-option__meta {
  font-size: 0.6875rem;
  line-height: 0.875rem;
}
</style>
