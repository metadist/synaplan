<template>
  <div ref="dropdownRef" class="relative" data-testid="comp-reasoning-level">
    <button
      ref="triggerRef"
      type="button"
      :class="['pill', isOpen && 'pill--active']"
      :aria-label="ariaLabel"
      aria-haspopup="listbox"
      :aria-controls="listboxId"
      :aria-expanded="isOpen"
      data-testid="btn-reasoning-toggle"
      @click="toggleOpen"
      @keydown.down.prevent="openFromKeyboard"
      @keydown.up.prevent="openFromKeyboard"
      @keydown.escape.stop="closeAndRestoreFocus"
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
      :id="listboxId"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-64 max-h-[60vh] overflow-y-auto scroll-thin"
      data-testid="dropdown-reasoning-panel"
      role="listbox"
      :aria-label="$t('chatInput.reasoningLevel.label')"
      @keydown.escape.stop.prevent="closeAndRestoreFocus"
    >
      <button
        v-for="(level, index) in levels"
        :key="level"
        ref="itemRefs"
        :class="['dropdown-item', modelValue === level && 'dropdown-item--active']"
        type="button"
        role="option"
        tabindex="-1"
        :aria-selected="modelValue === level"
        :data-testid="`btn-reasoning-${level}`"
        @click="selectLevel(level)"
        @keydown.down.prevent="focusAt(index + 1)"
        @keydown.up.prevent="focusAt(index - 1)"
        @keydown.home.prevent="focusAt(0)"
        @keydown.end.prevent="focusAt(levels.length - 1)"
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
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
const listboxId = useId()
const isOpen = ref(false)
const itemRefs = ref<HTMLElement[]>([])
const dropdownRef = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLElement | null>(null)

const levelLabel = (level: string) => t(`chatInput.reasoningLevel.${level}`)

const currentLabel = computed(() => (props.modelValue ? levelLabel(props.modelValue) : ''))

const ariaLabel = computed(() =>
  currentLabel.value
    ? `${t('chatInput.reasoningLevel.label')}, ${currentLabel.value}`
    : t('chatInput.reasoningLevel.label')
)

const focusAt = (index: number) => {
  const items = itemRefs.value
  if (items.length === 0) return
  const wrapped = ((index % items.length) + items.length) % items.length
  items[wrapped]?.focus()
}

const focusSelected = async () => {
  await nextTick()
  const selected = props.levels.indexOf(props.modelValue)
  focusAt(selected >= 0 ? selected : 0)
}

const openMenu = async () => {
  isOpen.value = true
  await focusSelected()
}

const toggleOpen = () => {
  triggerHapticImpact('light')
  if (isOpen.value) {
    closeAndRestoreFocus()
    return
  }
  void openMenu()
}

/** Arrow keys from the trigger open the list on the current choice. */
const openFromKeyboard = () => {
  if (!isOpen.value) {
    void openMenu()
    return
  }
  void focusSelected()
}

const closeDropdown = () => {
  isOpen.value = false
}

const closeAndRestoreFocus = () => {
  if (!isOpen.value) return
  triggerRef.value?.focus()
  isOpen.value = false
}

const selectLevel = (level: string) => {
  emit('update:modelValue', level)
  closeAndRestoreFocus()
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
