<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { Pencil, Trash2 } from 'lucide-vue-next'

interface Props {
  memory: UserMemory
}

const props = defineProps<Props>()

const emit = defineEmits<{
  edit: [memory: UserMemory]
  delete: [memory: UserMemory]
}>()

const { t } = useI18n()
const { formatDateTime } = useDateFormat()

const sourceLabel = computed(() => {
  return t(`memories.source.${props.memory.source}`)
})

const categoryLabel = computed(() => {
  return t(`memories.categories.${props.memory.category}`) || props.memory.category
})

const formattedDate = computed(() => {
  return formatDateTime(new Date(props.memory.updated * 1000))
})
</script>

<template>
  <div class="surface-card p-4 transition-all hover:shadow-lg">
    <!-- Header -->
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="surface-chip px-2 py-1 text-xs font-medium txt-brand">
            {{ categoryLabel }}
          </span>
          <span class="text-xs txt-secondary">
            {{ sourceLabel }}
          </span>
        </div>
        <h3 class="text-sm font-semibold txt-primary truncate">
          {{ memory.key }}
        </h3>
      </div>

      <!-- Actions -->
      <div class="flex items-center gap-1">
        <button
          class="icon-ghost"
          :aria-label="t('memories.actions.edit')"
          @click="emit('edit', memory)"
        >
          <Pencil :size="16" />
        </button>
        <button
          class="icon-ghost icon-ghost--danger"
          :aria-label="t('memories.actions.delete')"
          @click="emit('delete', memory)"
        >
          <Trash2 :size="16" />
        </button>
      </div>
    </div>

    <!-- Content -->
    <p class="text-sm txt-secondary leading-relaxed mb-2">
      {{ memory.value }}
    </p>

    <!-- Footer -->
    <div class="flex items-center justify-between text-xs txt-tertiary">
      <span>{{ formattedDate }}</span>
      <span v-if="memory.messageId" class="txt-brand"> #{{ memory.messageId }} </span>
    </div>
  </div>
</template>
