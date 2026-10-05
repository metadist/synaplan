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
      <div
        class="max-h-[min(16rem,36vh)] min-h-0 overflow-y-auto scroll-thin"
        role="listbox"
        :aria-label="$t('chatInput.model')"
        @keydown="onTypeaheadKeydown"
      >
        <button
          ref="defaultRef"
          :class="['dropdown-item', modelValue === null && 'dropdown-item--active']"
          type="button"
          role="option"
          :aria-selected="modelValue === null"
          data-testid="btn-model-default"
          @click="selectModel(null)"
          @keydown.down.prevent="focusNext"
          @keydown.up.prevent="focusPrevious"
        >
          <Icon icon="mdi:robot-outline" class="h-5 w-5 flex-shrink-0" />
          <div class="min-w-0 flex-1">
            <span class="text-sm font-medium">{{ $t('chatInput.modelDropdown.default') }}</span>
            <div class="text-xs txt-secondary">{{ defaultModelName }}</div>
          </div>
          <CheckIcon v-if="modelValue === null" class="h-5 w-5 flex-shrink-0 text-[var(--brand)]" />
        </button>

        <button
          v-for="model in chatModels"
          :key="model.id"
          ref="modelRefs"
          :class="['dropdown-item', modelValue === model.id && 'dropdown-item--active']"
          type="button"
          role="option"
          :aria-selected="modelValue === model.id"
          :data-testid="`btn-model-${model.id}`"
          @click="selectModel(model.id)"
          @keydown.down.prevent="focusNext"
          @keydown.up.prevent="focusPrevious"
        >
          <ServiceIcon :service="model.service" :size="20" />
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium">{{ model.name }}</span>
              <ModelCostBadge :model="model" :peers="chatModels" />
            </div>
            <div class="text-xs txt-secondary">{{ model.service }}</div>
          </div>
          <CheckIcon
            v-if="modelValue === model.id"
            class="h-5 w-5 flex-shrink-0 text-[var(--brand)]"
          />
        </button>
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
import { CheckIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'
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

const { t } = useI18n()
const aiConfigStore = useAiConfigStore()
const panelId = useId()
const isOpen = ref(false)
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

const { focusSelected, focusNext, focusPrevious, onTypeaheadKeydown, resetTypeahead } =
  useModelListKeyboard({
    defaultRef,
    modelRefs,
    labels: () => [
      t('chatInput.modelDropdown.default'),
      ...chatModels.value.map((model) => model.name),
    ],
  })

const focusSelectedModel = async () => {
  await nextTick()
  focusSelected(
    props.modelValue,
    chatModels.value.map((model) => model.id)
  )
}

const openMenu = async () => {
  isOpen.value = true
  await nextTick()
  placePanel()
  await focusSelectedModel()
}

const closeMenu = () => {
  if (!isOpen.value) return
  isOpen.value = false
  resetTypeahead()
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
    void openMenu()
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
