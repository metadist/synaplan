<template>
  <div
    class="flex items-center gap-1.5 flex-wrap"
    role="group"
    :aria-label="$t('iam.incoming.filter.label')"
    data-testid="filter-chat-kind"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      class="pill !min-h-0 !py-1 !px-2.5 text-xs relative"
      :class="{ 'pill--active': modelValue === option.value }"
      :aria-pressed="modelValue === option.value"
      :data-testid="`btn-chat-filter-${option.value}`"
      @click="emit('update:modelValue', option.value)"
    >
      <Icon :icon="option.icon" class="w-3.5 h-3.5" aria-hidden="true" />
      <span>{{ option.label }}</span>
      <span
        v-if="option.count !== undefined"
        class="text-[10px] opacity-70 tabular-nums"
        :data-testid="`text-chat-filter-count-${option.value}`"
        >{{ option.count }}</span
      >
      <span
        v-if="option.value === 'group' && newCount > 0"
        class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-[var(--status-error)] ring-2 ring-[var(--bg-card)]"
        data-testid="dot-chat-filter-group-new"
        :aria-label="$t('iam.incoming.newCount', { count: newCount })"
      />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { ChatListFilter } from '@/utils/chatKind'

const props = withDefaults(
  defineProps<{
    modelValue: ChatListFilter
    /** Show the Widget button (only lists that contain widget sessions). */
    showWidget?: boolean
    /** Optional per-filter counts rendered next to the label. */
    counts?: Partial<Record<ChatListFilter, number>>
    /** Incoming chats that arrived since the user last looked → red dot on Group. */
    newCount?: number
  }>(),
  { showWidget: false, counts: () => ({}), newCount: 0 }
)

const emit = defineEmits<{
  'update:modelValue': [value: ChatListFilter]
}>()

const { t } = useI18n()

const options = computed(() => {
  const list: { value: ChatListFilter; label: string; icon: string; count?: number }[] = [
    { value: 'all', label: t('iam.incoming.filter.all'), icon: 'mdi:view-list-outline' },
    { value: 'private', label: t('iam.incoming.filter.private'), icon: 'mdi:lock-outline' },
    { value: 'group', label: t('iam.incoming.filter.group'), icon: 'mdi:account-group-outline' },
  ]
  if (props.showWidget) {
    list.push({
      value: 'widget',
      label: t('iam.incoming.filter.widget'),
      icon: 'mdi:puzzle-outline',
    })
  }
  return list.map((o) => ({ ...o, count: props.counts[o.value] }))
})
</script>
