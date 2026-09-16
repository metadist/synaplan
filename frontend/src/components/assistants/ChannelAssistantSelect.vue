<template>
  <label v-if="visible" class="block" :data-testid="testId">
    <span class="txt-secondary text-sm">{{ label }}</span>
    <select
      class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="disabled"
      :value="modelValue == null ? '' : String(modelValue)"
      @change="onChange"
    >
      <option value="">{{ noneLabel }}</option>
      <option v-for="card in published" :key="card.id" :value="String(card.id)">
        {{ card.name }}
      </option>
    </select>
    <p v-if="hint" class="txt-secondary text-xs mt-1">{{ hint }}</p>
  </label>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { isAgentsEnabled } from '@/composables/useAgentsFeature'
import { agentsApi, type GalleryCard } from '@/services/api/agentsApi'

withDefaults(
  defineProps<{
    modelValue?: number | null
    label: string
    noneLabel: string
    hint?: string
    disabled?: boolean
    testId?: string
  }>(),
  {
    modelValue: null,
    hint: '',
    disabled: false,
    testId: 'select-channel-assistant',
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const cards = ref<GalleryCard[]>([])
const visible = computed(() => isAgentsEnabled())
const published = computed(() =>
  cards.value.filter(
    (card) => card.status === 'published' && (card.origin === 'mine' || card.origin === 'shared')
  )
)

// The runtime config that carries the assistants flag can resolve after this
// component mounts, so load on the flag turning on rather than on mount.
watch(
  visible,
  async (on) => {
    if (!on) {
      cards.value = []

      return
    }
    try {
      cards.value = await agentsApi.gallery()
    } catch {
      cards.value = []
    }
  },
  { immediate: true }
)

function onChange(event: Event): void {
  const raw = (event.target as HTMLSelectElement).value
  emit('update:modelValue', raw === '' ? null : Number(raw))
}
</script>
