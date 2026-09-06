<template>
  <span
    class="inline-flex items-center gap-1 rounded-md font-medium whitespace-nowrap max-w-full"
    :class="[sizeClass, toneClass]"
    :title="title"
    :data-testid="`pill-chat-kind-${kind}`"
    :data-kind="kind"
  >
    <Icon :icon="icon" :class="iconClass" aria-hidden="true" />
    <span class="truncate">{{ text }}</span>
    <span
      v-if="isNew"
      class="inline-flex items-center gap-1"
      data-testid="pill-chat-kind-new"
      :aria-label="$t('iam.incoming.new')"
    >
      <span class="w-1.5 h-1.5 rounded-full bg-[var(--status-error)]" />
      <span class="sr-only">{{ $t('iam.incoming.new') }}</span>
    </span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'

/**
 * Where a chat in a history list comes from:
 *   private  — my own conversation
 *   group    — shared with a group I belong to (label = group name)
 *   everyone — shared with everyone on this instance
 *   direct   — shared with me personally (label = owner name)
 *   widget   — a visitor session of one of my chat widgets
 */
export type ChatKind = 'private' | 'group' | 'everyone' | 'direct' | 'widget'

const props = withDefaults(
  defineProps<{
    kind: ChatKind
    /** Group name (group) or owner name (direct); ignored for other kinds. */
    label?: string | null
    /** Red dot: this chat arrived after the user last opened Incoming. */
    isNew?: boolean
    size?: 'xs' | 'sm'
  }>(),
  { label: null, isNew: false, size: 'xs' }
)

const { t } = useI18n()

const sizeClass = computed(() =>
  props.size === 'sm' ? 'px-2 py-1 text-xs' : 'px-1.5 py-0.5 text-[11px]'
)
const iconClass = computed(() => (props.size === 'sm' ? 'w-3.5 h-3.5' : 'w-3 h-3'))

const icon = computed(() => {
  switch (props.kind) {
    case 'group':
      return 'mdi:account-group-outline'
    case 'everyone':
      return 'mdi:earth'
    case 'direct':
      return 'mdi:account-arrow-left-outline'
    case 'widget':
      return 'mdi:puzzle-outline'
    default:
      return 'mdi:lock-outline'
  }
})

const toneClass = computed(() => {
  switch (props.kind) {
    case 'group':
    case 'everyone':
    case 'direct':
      return 'bg-[var(--brand-alpha-light)] text-[var(--brand)]'
    case 'widget':
      return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
    default:
      return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
  }
})

const text = computed(() => {
  switch (props.kind) {
    case 'group':
      return props.label?.trim() || t('iam.incoming.pill.group')
    case 'everyone':
      return t('iam.incoming.pill.everyone')
    case 'direct':
      return props.label?.trim()
        ? t('iam.incoming.pill.from', { name: props.label.trim() })
        : t('iam.incoming.pill.direct')
    case 'widget':
      return t('iam.incoming.pill.widget')
    default:
      return t('iam.incoming.pill.private')
  }
})

const title = computed(() => {
  switch (props.kind) {
    case 'group':
      return t('iam.incoming.pill.groupTitle', { name: text.value })
    case 'everyone':
      return t('iam.incoming.pill.everyoneTitle')
    case 'direct':
      return t('iam.incoming.pill.directTitle')
    case 'widget':
      return t('iam.incoming.pill.widgetTitle')
    default:
      return t('iam.incoming.pill.privateTitle')
  }
})
</script>
