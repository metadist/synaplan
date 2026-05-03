<template>
  <div ref="dropdownRef" class="relative" data-testid="comp-model-dropdown">
    <button
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="$t('chatInput.model')"
      data-testid="btn-model-toggle"
      @click="toggleOpen"
      @keydown.escape="closeDropdown"
    >
      <Icon icon="mdi:robot-outline" class="w-4 h-4 md:w-5 md:h-5" />
      <span class="text-xs md:text-sm font-medium hidden sm:inline truncate max-w-[120px]">
        {{ selectedModelName }}
      </span>
      <ModelCostBadge v-if="selectedModelObj" :model="selectedModelObj" :peers="chatModels" />
      <ChevronUpIcon class="w-4 h-4" />
    </button>
    <div
      v-if="isOpen"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-80 max-h-[60vh] overflow-y-auto scroll-thin"
      data-testid="dropdown-model-panel"
      @keydown.escape="closeDropdown"
    >
      <!-- Default Option -->
      <button
        ref="itemRefs"
        :class="['dropdown-item', modelValue === null && 'dropdown-item--active']"
        type="button"
        data-testid="btn-model-default"
        @click="selectModel(null)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon icon="mdi:robot-outline" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ $t('chatInput.modelDropdown.default') }}</span>
          </div>
          <div class="text-xs txt-secondary">
            {{ defaultModelName }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon v-if="modelValue === null" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
        </Transition>
      </button>

      <!-- Chat Models -->
      <button
        v-for="model in chatModels"
        :key="model.id"
        ref="itemRefs"
        :class="['dropdown-item', modelValue === model.id && 'dropdown-item--active']"
        type="button"
        :data-testid="`btn-model-${model.id}`"
        @click="selectModel(model.id)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <Icon :icon="getProviderIcon(model.service)" class="w-5 h-5 flex-shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ model.name }}</span>
            <ModelCostBadge :model="model" :peers="chatModels" />
          </div>
          <div class="text-xs txt-secondary">
            {{ model.service }}
          </div>
        </div>
        <Transition name="check-fade">
          <CheckIcon
            v-if="modelValue === model.id"
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { ChevronUpIcon, CheckIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useI18n } from 'vue-i18n'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import type { AIModel } from '@/types/ai-models'

const props = defineProps<{
  modelValue: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { t } = useI18n()
const aiConfigStore = useAiConfigStore()
const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])
const dropdownRef = ref<HTMLElement | null>(null)

const chatModels = computed(() => {
  const models = aiConfigStore.models.CHAT || []
  return models.filter((m) => m.service !== 'test')
})
const defaultModelId = computed(() => aiConfigStore.defaults.CHAT)

const defaultModelName = computed(() => {
  if (!defaultModelId.value) return t('chatInput.modelDropdown.default')
  const model = chatModels.value.find((m) => m.id === defaultModelId.value)
  return model ? model.name : t('chatInput.modelDropdown.default')
})

const selectedModelObj = computed((): AIModel | null => {
  if (props.modelValue === null) return null
  return chatModels.value.find((m) => m.id === props.modelValue) ?? null
})

const selectedModelName = computed(() => {
  if (selectedModelObj.value) return selectedModelObj.value.name
  return t('chatInput.modelDropdown.default')
})

const getProviderIcon = (provider: string): string => {
  const p = provider.toLowerCase()
  if (p.includes('openai')) return 'mdi:robot-outline'
  if (p.includes('anthropic')) return 'mdi:robot-outline'
  if (p.includes('google')) return 'mdi:google'
  if (p.includes('ollama')) return 'mdi:server'
  if (p.includes('cloudflare')) return 'simple-icons:cloudflare'
  if (p.includes('groq')) return 'mdi:lightning-bolt'
  return 'mdi:robot-outline'
}

const toggleOpen = () => {
  isOpen.value = !isOpen.value
}

const closeDropdown = () => {
  isOpen.value = false
}

const selectModel = (modelId: number | null) => {
  emit('update:modelValue', modelId)
  closeDropdown()
}

const focusNext = () => {
  const currentIndex = itemRefs.value.findIndex((el) => el === document.activeElement)
  const nextIndex = (currentIndex + 1) % itemRefs.value.length
  itemRefs.value[nextIndex]?.focus()
}

const focusPrevious = () => {
  const currentIndex = itemRefs.value.findIndex((el) => el === document.activeElement)
  const prevIndex = currentIndex <= 0 ? itemRefs.value.length - 1 : currentIndex - 1
  itemRefs.value[prevIndex]?.focus()
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!isOpen.value) return

  if (dropdownRef.value && dropdownRef.value.contains(target)) {
    return
  }

  closeDropdown()
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))
</script>

<style scoped>
.check-fade-enter-active {
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.check-fade-leave-active {
  transition: all 0.2s ease-in;
}

.check-fade-enter-from {
  opacity: 0;
  transform: scale(0.5) rotate(-90deg);
}

.check-fade-leave-to {
  opacity: 0;
  transform: scale(0.8);
}
</style>
