<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const query = defineModel<string>({ required: true })

defineProps<{
  testId: string
  label: string
  placeholder: string
}>()

const emit = defineEmits<{
  submit: []
  escape: []
}>()

const { t } = useI18n()
const input = ref<HTMLInputElement | null>(null)

function focus(): void {
  input.value?.focus()
}

function clear(): void {
  query.value = ''
  focus()
}

function onEnter(event: KeyboardEvent): void {
  if (event.isComposing || event.keyCode === 229) return
  event.preventDefault()
  emit('submit')
}

defineExpose({ focus })
</script>

<template>
  <div
    class="shrink-0 border-b border-light-border/30 px-2 pb-3 pt-2 dark:border-dark-border/20"
    :data-testid="`section-${testId}`"
  >
    <label class="sr-only" :for="testId">{{ label }}</label>
    <div class="relative">
      <MagnifyingGlassIcon
        class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 txt-secondary"
        aria-hidden="true"
      />
      <input
        :id="testId"
        ref="input"
        v-model="query"
        type="text"
        autocomplete="off"
        spellcheck="false"
        enterkeyhint="go"
        class="w-full rounded-xl surface-card border border-light-border/30 py-1.5 pl-9 pr-9 text-sm txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] dark:border-dark-border/20"
        :placeholder="placeholder"
        :data-testid="`input-${testId}`"
        @keydown.enter="onEnter"
        @keydown.escape.stop.prevent="emit('escape')"
      />
      <button
        v-if="query"
        type="button"
        class="icon-ghost absolute right-1.5 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md"
        :aria-label="t('config.aiModels.dropdownClearFilter')"
        :data-testid="`btn-${testId}-clear`"
        @click="clear"
      >
        <XMarkIcon class="h-3.5 w-3.5" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>
