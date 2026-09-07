<template>
  <div ref="root" class="relative" data-testid="iam-permission-select">
    <button
      type="button"
      class="dropdown-trigger w-full justify-between border border-light-border/30 dark:border-dark-border/8 bg-[var(--bg-card)] txt-primary text-sm rounded-lg px-3 py-2 min-h-[42px]"
      :aria-expanded="open"
      :aria-label="$t('iam.dialog.permission')"
      data-testid="btn-iam-permission"
      @click="open = !open"
    >
      <span>{{ $t(`iam.permission.${modelValue}`) }}</span>
      <Icon icon="heroicons:chevron-down" class="w-4 h-4 txt-secondary shrink-0" />
    </button>
    <ul
      v-if="open"
      class="dropdown-panel absolute z-50 mt-1 w-max min-w-full max-w-80 right-0"
      data-testid="list-iam-permission"
    >
      <li v-for="level in levels" :key="level">
        <button
          type="button"
          class="dropdown-item w-full flex-col items-start gap-0.5"
          :class="level === modelValue ? 'dropdown-item--active' : ''"
          :data-testid="`btn-iam-permission-${level}`"
          @click="pick(level)"
        >
          <span class="font-medium">{{ $t(`iam.permission.${level}`) }}</span>
          <span class="text-xs txt-secondary font-normal">{{ consequence(level) }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { type ShareKind, shareConsequenceKey } from '@/utils/shareCopy'

const props = defineProps<{
  modelValue: string
  allowed: string[]
  kind: ShareKind
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

const levels = computed(() =>
  ['read', 'use', 'edit', 'manage'].filter((level) => props.allowed.includes(level))
)

const consequence = (level: string) => t(shareConsequenceKey(props.kind, level))

const pick = (level: string) => {
  emit('update:modelValue', level)
  open.value = false
}

const onDocumentClick = (event: MouseEvent) => {
  if (root.value && !root.value.contains(event.target as Node)) {
    open.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onDocumentClick))
onUnmounted(() => document.removeEventListener('mousedown', onDocumentClick))
</script>
