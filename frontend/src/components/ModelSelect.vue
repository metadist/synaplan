<template>
  <div class="relative isolate" data-testid="comp-model-select">
    <button
      class="dropdown-trigger"
      data-testid="btn-model-select-toggle"
      @click="isOpen = !isOpen"
    >
      <ServiceIcon :service="modelsStore.selectedProvider" :size="20" />
      <span class="text-xs md:text-sm font-medium hidden sm:inline">{{ currentLabel }}</span>
      <ChevronDownIcon class="w-4 h-4" />
    </button>

    <div
      v-if="isOpen"
      role="menu"
      class="absolute bottom-full mb-2 left-0 min-w-[220px] max-h-[60vh] overflow-auto scroll-thin dropdown-panel z-[70]"
      data-testid="dropdown-model-select"
    >
      <button
        v-for="option in modelOptions"
        :key="`${option.provider}-${option.model}`"
        role="menuitem"
        :class="[
          'dropdown-item',
          modelsStore.selectedProvider === option.provider &&
          modelsStore.selectedModel === option.model
            ? 'dropdown-item--active'
            : '',
        ]"
        @click="selectModel(option)"
      >
        <ServiceIcon :service="option.provider" :size="20" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">{{ option.label }}</div>
          <div class="text-xs txt-secondary">{{ option.provider }}</div>
        </div>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'
import { useModelsStore } from '@/stores/models'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'

const modelsStore = useModelsStore()
const isOpen = ref(false)

const defaultModelOptions = [
  { provider: 'OpenAI', model: 'gpt-4', label: 'GPT-4' },
  { provider: 'OpenAI', model: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
  { provider: 'OpenAI', model: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo' },
  { provider: 'Anthropic', model: 'claude-3-opus', label: 'Claude 3 Opus' },
  { provider: 'Anthropic', model: 'claude-3-sonnet', label: 'Claude 3 Sonnet' },
  { provider: 'Google', model: 'gemini-pro', label: 'Gemini Pro' },
]

const modelOptions = computed(() => {
  return modelsStore.chatModels.length > 0 ? modelsStore.chatModels : defaultModelOptions
})

const currentLabel = computed(() => {
  const current = modelOptions.value.find(
    (opt) =>
      opt.provider === modelsStore.selectedProvider && opt.model === modelsStore.selectedModel
  )
  return current ? current.label : 'Model'
})

const selectModel = (option: { provider: string; model: string; label: string }) => {
  modelsStore.setModel(option.provider, option.model)
  isOpen.value = false
}

const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  const container = target.closest('.relative')
  if (!container || !container.contains(event.target as Node)) {
    isOpen.value = false
  }
}

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && isOpen.value) {
    isOpen.value = false
  }
}

onMounted(() => {
  // Model options now come from aiConfigStore/useModelSelection, so avoid injecting mock data.
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>
