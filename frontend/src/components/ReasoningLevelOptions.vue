<template>
  <div
    ref="rootRef"
    class="relative mt-1 shrink-0 border-t border-light-border/30 pt-1 dark:border-dark-border/20"
    @keydown.escape.stop.prevent="onEscape"
  >
    <button
      ref="triggerRef"
      type="button"
      class="dropdown-item w-full"
      :aria-label="ariaLabel"
      :aria-expanded="isOpen"
      aria-haspopup="listbox"
      data-testid="btn-reasoning-toggle"
      @click.stop="toggle"
      @keydown.down.prevent="openMenu"
      @keydown.up.prevent="openMenu"
    >
      <span class="min-w-0 flex-1 text-sm font-medium">{{ label }}</span>
      <span class="flex-shrink-0 text-sm txt-secondary">{{ currentLabel }}</span>
      <ChevronUpIcon class="h-4 w-4 flex-shrink-0" />
    </button>

    <div
      v-if="isOpen"
      class="dropdown-up left-0 right-0"
      role="listbox"
      :aria-label="label"
      data-testid="dropdown-reasoning-panel"
    >
      <button
        v-for="(level, index) in levels"
        :key="level"
        ref="itemRefs"
        type="button"
        role="option"
        tabindex="-1"
        :aria-selected="modelValue === level"
        :data-testid="`btn-reasoning-${level}`"
        :class="['dropdown-item', modelValue === level && 'dropdown-item--active']"
        @click="choose(level)"
        @keydown.down.prevent="focusAt(index + 1)"
        @keydown.up.prevent="focusAt(index - 1)"
        @keydown.home.prevent="focusAt(0)"
        @keydown.end.prevent="focusAt(levels.length - 1)"
      >
        <span class="min-w-0 flex-1 text-sm font-medium">{{ levelLabel(level) }}</span>
        <CheckIcon v-if="modelValue === level" class="h-5 w-5 flex-shrink-0 text-[var(--brand)]" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  levels: string[]
  modelValue: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  close: []
}>()

const { t } = useI18n()
const isOpen = ref(false)
const rootRef = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLElement | null>(null)
const itemRefs = ref<HTMLElement[]>([])

const label = computed(() => t('chatInput.reasoningLevel.label'))
const levelLabel = (level: string) => t(`chatInput.reasoningLevel.${level}`)
const currentLabel = computed(() => (props.modelValue ? levelLabel(props.modelValue) : ''))
const ariaLabel = computed(() =>
  currentLabel.value ? `${label.value}, ${currentLabel.value}` : label.value
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
  if (isOpen.value) {
    await focusSelected()
    return
  }
  isOpen.value = true
  await focusSelected()
}

const closeMenu = () => {
  if (!isOpen.value) return
  isOpen.value = false
  void nextTick(() => triggerRef.value?.focus())
}

const toggle = () => {
  if (isOpen.value) closeMenu()
  else void openMenu()
}

const choose = (level: string) => {
  emit('update:modelValue', level)
  closeMenu()
}

const onEscape = () => {
  if (isOpen.value) {
    closeMenu()
    return
  }
  emit('close')
}

const onDocumentClick = (event: MouseEvent) => {
  if (!isOpen.value) return
  const target = event.target as Node
  if (rootRef.value?.contains(target)) return
  isOpen.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>
