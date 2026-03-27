<template>
  <div class="relative">
    <button
      ref="containerRef"
      type="button"
      :disabled="disabled"
      :class="[
        'w-full px-4 py-2.5 pl-10 pr-10 rounded-lg surface-card border txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all text-left',
        'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
        isOpen && 'ring-2 ring-[var(--brand)]',
        disabled && 'opacity-50 cursor-not-allowed',
      ]"
      @click="toggle"
    >
      <span class="flex items-center gap-2 truncate">
        <span class="truncate">{{ selectedLabel }}</span>
        <ModelCostBadge v-if="selectedModel" :model="selectedModel" :peers="allModels" />
      </span>
    </button>

    <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
      <GroqIcon
        v-if="selectedModel?.service?.toLowerCase().includes('groq')"
        :size="16"
        class-name="txt-primary"
      />
      <Icon v-else :icon="getProviderIcon(selectedModel?.service ?? '')" class="w-4 h-4" />
    </div>

    <ChevronDownIcon
      :class="[
        'absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none transition-transform',
        isOpen && 'rotate-180',
      ]"
    />

    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="panelRef"
        class="fixed z-[9999] overflow-y-auto dropdown-panel"
        :style="panelStyle"
      >
        <button
          v-if="defaultOption"
          type="button"
          :class="['dropdown-item w-full', isDefaultSelected && 'dropdown-item--active']"
          @click="selectDefault"
        >
          <span class="txt-secondary italic">{{ defaultOption }}</span>
        </button>

        <template v-for="group in groups" :key="group.capability">
          <div
            v-if="groups.length > 1"
            class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest txt-secondary bg-gray-50 dark:bg-gray-800 sticky top-0 z-10"
          >
            {{ group.label }}
          </div>
          <button
            v-for="model in group.models"
            :key="model.id"
            type="button"
            :class="['dropdown-item w-full', isSelected(model) && 'dropdown-item--active']"
            @click="selectModel(model)"
          >
            <GroqIcon
              v-if="model.service.toLowerCase().includes('groq')"
              :size="20"
              class-name="flex-shrink-0"
            />
            <Icon v-else :icon="getProviderIcon(model.service)" class="w-5 h-5 flex-shrink-0" />
            <div class="flex-1 min-w-0 text-left">
              <div class="flex items-center gap-2">
                <span class="font-medium truncate">{{ model.name }}</span>
                <ModelCostBadge :model="model" :peers="allModels" />
              </div>
              <div class="text-xs txt-secondary truncate">{{ model.service }}</div>
            </div>
          </button>
        </template>

        <div v-if="loading" class="dropdown-item w-full pointer-events-none">
          <Icon icon="heroicons:arrow-path" class="w-4 h-4 animate-spin txt-secondary" />
          <span class="txt-secondary">Loading models...</span>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, nextTick, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import { getProviderIcon } from '@/utils/providerIcons'
import type { AIModel, Capability } from '@/types/ai-models'

export interface ModelGroup {
  label: string
  models: AIModel[]
  capability: Capability | string
}

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null
    groups: ModelGroup[]
    loading?: boolean
    disabled?: boolean
    valueFormat?: 'id' | 'nameService'
    defaultOption?: string
  }>(),
  {
    loading: false,
    disabled: false,
    valueFormat: 'nameService',
    defaultOption: undefined,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number | null]
}>()

const containerRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)
const panelStyle = ref<Record<string, string>>({})

const PANEL_MAX_HEIGHT = 450

const updatePanelPosition = () => {
  if (!containerRef.value) return
  const rect = containerRef.value.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom - 8
  const spaceAbove = rect.top - 8
  const maxH = Math.min(PANEL_MAX_HEIGHT, Math.max(spaceBelow, spaceAbove))
  const openAbove = spaceBelow < maxH && spaceAbove > spaceBelow

  panelStyle.value = {
    left: `${rect.left}px`,
    width: `${rect.width}px`,
    maxHeight: `${maxH}px`,
    ...(openAbove
      ? { bottom: `${window.innerHeight - rect.top + 4}px`, top: 'auto' }
      : { top: `${rect.bottom + 4}px`, bottom: 'auto' }),
  }
}

const allModels = computed(() => props.groups.flatMap((g) => g.models))

const formatValue = (model: AIModel): string | number => {
  return props.valueFormat === 'id' ? model.id : `${model.name} (${model.service})`
}

const selectedModel = computed((): AIModel | null => {
  if (props.modelValue == null) return null
  for (const group of props.groups) {
    const found = group.models.find((m) => formatValue(m) === props.modelValue)
    if (found) return found
  }
  return null
})

const isDefaultSelected = computed(() => {
  if (!props.defaultOption) return false
  return props.modelValue === 'default' || props.modelValue == null
})

const selectedLabel = computed(() => {
  if (selectedModel.value) {
    return `${selectedModel.value.name} (${selectedModel.value.service})`
  }
  if (props.defaultOption && isDefaultSelected.value) {
    return props.defaultOption
  }
  return props.defaultOption ?? '—'
})

const isSelected = (model: AIModel) => formatValue(model) === props.modelValue

const toggle = () => {
  if (props.disabled) return
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    nextTick(updatePanelPosition)
  }
}

const selectModel = (model: AIModel) => {
  emit('update:modelValue', formatValue(model))
  isOpen.value = false
}

const selectDefault = () => {
  emit('update:modelValue', props.valueFormat === 'id' ? null : 'default')
  isOpen.value = false
}

const onClickOutside = (event: MouseEvent) => {
  if (!isOpen.value) return
  const target = event.target as Node
  const inButton = containerRef.value?.contains(target)
  const inPanel = panelRef.value?.contains(target)
  if (!inButton && !inPanel) {
    isOpen.value = false
  }
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && isOpen.value) {
    isOpen.value = false
  }
}

const onScroll = () => {
  if (isOpen.value) updatePanelPosition()
}

onMounted(() => {
  document.addEventListener('click', onClickOutside)
  document.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onScroll, true)
  window.addEventListener('resize', onScroll)
})

onUnmounted(() => {
  document.removeEventListener('click', onClickOutside)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', onScroll)
})
</script>
