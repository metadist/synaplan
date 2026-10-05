<template>
  <div
    class="mt-1 border-t border-light-border/30 pt-1 dark:border-dark-border/20"
    role="listbox"
    :aria-label="label"
    data-testid="dropdown-reasoning-panel"
    @keydown.escape.stop.prevent="emit('close')"
  >
    <p class="px-3 pb-0.5 pt-1 text-xs font-medium txt-secondary">{{ label }}</p>
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
      @click="emit('update:modelValue', level)"
      @keydown.down.prevent="focusAt(index + 1)"
      @keydown.up.prevent="focusAt(index - 1)"
      @keydown.home.prevent="focusAt(0)"
      @keydown.end.prevent="focusAt(levels.length - 1)"
    >
      <span class="min-w-0 flex-1 text-sm font-medium">{{ levelLabel(level) }}</span>
      <CheckIcon v-if="modelValue === level" class="h-5 w-5 flex-shrink-0 text-[var(--brand)]" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { CheckIcon } from '@heroicons/vue/24/outline'
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
const itemRefs = ref<HTMLElement[]>([])

const label = computed(() => t('chatInput.reasoningLevel.label'))
const levelLabel = (level: string) => t(`chatInput.reasoningLevel.${level}`)

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

defineExpose({ focusSelected })
</script>
