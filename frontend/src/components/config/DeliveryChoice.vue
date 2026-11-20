<template>
  <div data-testid="delivery-choice">
    <p class="text-sm font-medium txt-primary mb-1">{{ $t('config.savedTasks.delivery.label') }}</p>
    <div
      class="grid grid-cols-1 sm:grid-cols-2 gap-2"
      role="radiogroup"
      :aria-label="$t('config.savedTasks.delivery.label')"
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
        :data-testid="`btn-delivery-${option.key}`"
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
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { SavedTaskDeliveryChoice } from '@/services/api/savedTasksApi'

defineProps<{
  modelValue: SavedTaskDeliveryChoice
}>()

const emit = defineEmits<{
  'update:modelValue': [value: SavedTaskDeliveryChoice]
}>()

const { t } = useI18n()

const options = computed(() => [
  {
    key: 'chat' as const,
    icon: 'heroicons:chat-bubble-left-right',
    name: t('config.savedTasks.delivery.chat'),
    summary: t('config.savedTasks.delivery.chatHint'),
  },
  {
    key: 'email' as const,
    icon: 'heroicons:envelope',
    name: t('config.savedTasks.delivery.email'),
    summary: t('config.savedTasks.delivery.emailHint'),
  },
])
</script>
