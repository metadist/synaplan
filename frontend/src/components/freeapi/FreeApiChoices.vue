<template>
  <div
    role="radiogroup"
    :aria-label="$t('freeApi.welcome.choose')"
    class="flex flex-col gap-3"
    data-testid="freeapi-choices"
  >
    <label
      v-for="choice in choices"
      :key="choice.id"
      class="surface-chip rounded-xl p-4 flex items-start gap-3 cursor-pointer transition outline-offset-2 outline-[var(--brand)] has-[:focus-visible]:outline-2"
      :class="modelValue === choice.id ? 'outline-2' : 'outline-0 hover:outline-1'"
      :data-testid="`freeapi-choice-${choice.id}`"
    >
      <input
        type="radio"
        class="sr-only"
        name="freeapi-start-choice"
        :value="choice.id"
        :checked="modelValue === choice.id"
        @change="emit('update:modelValue', choice.id)"
      />
      <component
        :is="choice.icon"
        class="w-6 h-6 flex-shrink-0 mt-0.5"
        :class="modelValue === choice.id ? 'txt-brand' : 'txt-secondary'"
        aria-hidden="true"
      />
      <span class="flex-1 min-w-0">
        <span class="flex flex-wrap items-center gap-2">
          <span
            class="text-base font-semibold"
            :class="modelValue === choice.id ? 'txt-brand' : 'txt-primary'"
          >
            {{ $t(`freeApi.choice.${choice.id}.title`) }}
          </span>
          <span
            v-if="choice.id === 'demo'"
            class="text-[11px] font-medium leading-none px-1.5 py-1 rounded-full bg-[var(--brand-alpha-light)] txt-brand"
          >
            {{ $t('freeApi.choice.demo.badge') }}
          </span>
          <span
            v-else-if="choice.id === 'ollama' && ollamaBadge"
            class="text-[11px] font-medium leading-none px-1.5 py-1 rounded-full surface-card txt-secondary"
            data-testid="freeapi-ollama-badge"
          >
            {{ ollamaBadge }}
          </span>
        </span>
        <span class="block text-sm txt-secondary mt-1">
          {{ $t(`freeApi.choice.${choice.id}.text`) }}
        </span>
      </span>
    </label>
  </div>
</template>

<script setup lang="ts">
import { ComputerDesktopIcon, KeyIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import type { FreeApiChoice } from '@/utils/freeApiCopy'

defineProps<{
  modelValue: FreeApiChoice
  ollamaBadge?: string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: FreeApiChoice] }>()

const choices = [
  { id: 'demo', icon: SparklesIcon },
  { id: 'ollama', icon: ComputerDesktopIcon },
  { id: 'key', icon: KeyIcon },
] as const satisfies ReadonlyArray<{ id: FreeApiChoice; icon: unknown }>
</script>
