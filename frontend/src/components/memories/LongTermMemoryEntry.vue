<template>
  <article
    class="surface-card border border-light-border/30 dark:border-dark-border/20 rounded-xl p-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
    data-testid="item-long-term-entry"
  >
    <div class="min-w-0">
      <h3 class="txt-primary text-sm font-medium break-words">{{ entry.title }}</h3>
      <p class="txt-secondary text-xs mt-1 break-words">{{ meta }}</p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <button
        v-if="canOpen"
        type="button"
        class="icon-ghost inline-flex items-center gap-1 text-sm"
        :aria-label="t('memories.longTerm.openInChat')"
        data-testid="btn-long-term-open"
        @click="emit('open')"
      >
        <ChatBubbleLeftRightIcon class="w-5 h-5" />
        <span>{{ t('memories.longTerm.openInChat') }}</span>
      </button>
      <p v-else class="txt-secondary text-xs" data-testid="text-long-term-open-unavailable">
        {{ t('memories.longTerm.openUnavailable') }}
      </p>
      <button
        type="button"
        class="icon-ghost icon-ghost--danger p-2 rounded-xl"
        :aria-label="t('memories.longTerm.delete')"
        :title="t('memories.longTerm.delete')"
        data-testid="btn-long-term-delete"
        @click="emit('delete')"
      >
        <TrashIcon class="w-5 h-5" />
      </button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { ChatBubbleLeftRightIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import type { MessageDigestListEntry } from '@/services/api/messageDigestEntriesApi'

const props = defineProps<{
  entry: MessageDigestListEntry
  canOpen: boolean
}>()

const emit = defineEmits<{
  open: []
  delete: []
}>()

const { t } = useI18n()
const { formatDateTime } = useDateFormat()

// Digest rows store the lowercased BMESSTYPE code of the source message.
const CHANNEL_LABEL_KEYS: Record<string, string> = {
  web: 'web',
  wtsp: 'whatsapp',
  wa: 'whatsapp',
  mail: 'email',
  tgrm: 'telegram',
  wdgt: 'widget',
  api: 'api',
}

const meta = computed(() => {
  const { entry } = props
  const channelKey = CHANNEL_LABEL_KEYS[entry.channel.toLowerCase()] ?? 'other'
  const chat = entry.chatTitle?.trim() ? entry.chatTitle : t('memories.longTerm.unknownChat')
  return [
    formatDateTime(new Date(entry.sourceDate * 1000)),
    t(`memories.longTerm.channels.${channelKey}`),
    chat,
  ].join(' · ')
})
</script>
