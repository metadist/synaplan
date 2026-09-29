<template>
  <div ref="dropdownRef" class="relative" data-testid="comp-reasoning-level">
    <button
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="ariaLabel"
      :aria-expanded="isOpen"
      data-testid="btn-reasoning-toggle"
      @click="toggleOpen"
      @keydown.escape="closeDropdown"
    >
      <LightBulbIcon class="w-4 h-4 md:w-5 md:h-5" />
      <span class="text-xs md:text-sm font-medium">{{ $t('chatInput.reasoningLevel.label') }}</span>
      <span
        v-if="currentLabel"
        class="text-xs md:text-sm txt-secondary truncate max-w-[22vw] sm:max-w-[96px]"
        data-testid="reasoning-level-current"
      >
        {{ currentLabel }}
      </span>
      <ChevronUpIcon class="w-4 h-4" />
    </button>
    <div
      v-if="isOpen"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-64 max-h-[60vh] overflow-y-auto scroll-thin"
      data-testid="dropdown-reasoning-panel"
      role="listbox"
      :aria-label="$t('chatInput.reasoningLevel.label')"
      @keydown.escape="closeDropdown"
    >
      <button
        v-for="level in levels"
        :key="level"
        ref="itemRefs"
        :class="['dropdown-item', modelValue === level && 'dropdown-item--active']"
        type="button"
        role="option"
        :aria-selected="modelValue === level"
        :data-testid="`btn-reasoning-${level}`"
        @click="selectLevel(level)"
        @keydown.down.prevent="focusNext"
        @keydown.up.prevent="focusPrevious"
      >
        <span class="flex-1 min-w-0 text-sm font-medium">{{ levelLabel(level) }}</span>
        <Transition name="check-fade">
          <CheckIcon
            v-if="modelValue === level"
            class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
          />
        </Transition>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckIcon, ChevronUpIcon, LightBulbIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

const props = defineProps<{
  levels: string[]
  modelValue: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])
const dropdownRef = ref<HTMLElement | null>(null)

const levelLabel = (level: string) => t(`chatInput.reasoningLevel.${level}`)

const currentLabel = computed(() => (props.modelValue ? levelLabel(props.modelValue) : ''))

const ariaLabel = computed(() =>
  currentLabel.value
    ? `${t('chatInput.reasoningLevel.label')}, ${currentLabel.value}`
    : t('chatInput.reasoningLevel.label')
)

const toggleOpen = () => {
  triggerHapticImpact('light')
  isOpen.value = !isOpen.value
}

const closeDropdown = () => {
  isOpen.value = false
}

const selectLevel = (level: string) => {
  emit('update:modelValue', level)
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
  if (!isOpen.value) return
  const target = e.target as HTMLElement
  if (dropdownRef.value && dropdownRef.value.contains(target)) return
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
