<template>
  <div data-testid="step-picker">
    <p class="text-sm font-medium txt-primary mb-1">{{ $t('workflows.addStep') }}</p>
    <div
      class="grid grid-cols-1 sm:grid-cols-2 gap-2"
      role="radiogroup"
      :aria-label="$t('workflows.addStep')"
    >
      <button
        v-for="option in options"
        :key="option.key"
        type="button"
        role="radio"
        :aria-checked="modelValue === option.key"
        class="text-left rounded-xl px-3 py-3 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        :class="
          modelValue === option.key
            ? 'bg-[var(--brand-alpha-light)] ring-2 ring-[var(--brand)]'
            : 'surface-card ring-1 ring-[var(--divider)] hover:bg-[var(--brand-alpha-light)]'
        "
        :data-testid="`btn-step-kind-${option.key}`"
        @click="emit('update:modelValue', option.key)"
      >
        <span class="flex items-start gap-3">
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-alpha-light)]"
          >
            <Icon :icon="option.icon" class="w-4 h-4 text-[var(--brand)]" aria-hidden="true" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="text-sm font-medium txt-primary">{{ option.name }}</span>
            <span class="block text-xs txt-secondary mt-0.5 leading-relaxed">
              {{ option.summary }}
            </span>
          </span>
        </span>
      </button>
    </div>
    <p
      v-if="hint"
      class="text-xs txt-secondary mt-3 leading-relaxed"
      data-testid="step-picker-hint"
    >
      {{ hint }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { BUILTIN_STEP_KINDS } from './stepTypes'
import type { RegistryTool } from '@/services/api/toolsApi'

const props = defineProps<{
  modelValue: string
  tools?: RegistryTool[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t, te } = useI18n()

const icons: Record<string, string> = {
  email_search: 'heroicons:envelope',
  summarize: 'heroicons:document-text',
  chat: 'heroicons:chat-bubble-left-right',
  email_me: 'heroicons:paper-airplane',
  condition: 'heroicons:funnel',
  outbound_webhook: 'heroicons:arrow-up-right',
  tool_call: 'heroicons:wrench-screwdriver',
}

const options = computed(() => {
  const builtins = BUILTIN_STEP_KINDS.map((key) => ({
    key,
    icon: icons[key] ?? 'heroicons:squares-2x2',
    name: t(`workflows.kinds.${key}`),
    summary: t(`workflows.kindHints.${key}`),
  }))
  const tools = (props.tools ?? [])
    .filter((tool) => tool.name)
    .map((tool) => ({
      key: `tool:${tool.name}`,
      icon: icons.tool_call,
      name: tool.title || tool.name,
      summary: tool.description || t('workflows.kindHints.tool_call'),
    }))
  return [...builtins, ...tools]
})

const hint = computed(() => {
  const key = props.modelValue.startsWith('tool:')
    ? 'workflows.kindHints.tool_call'
    : `workflows.kindHints.${props.modelValue}`
  return te(key) ? t(key) : ''
})
</script>
