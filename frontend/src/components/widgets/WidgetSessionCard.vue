<template>
  <div
    class="surface-card p-4 hover:shadow-lg transition-shadow cursor-pointer group"
    @click="$emit('view', session)"
  >
    <!-- Header -->
    <div class="flex items-start justify-between mb-3">
      <div class="flex-1 min-w-0 pr-2">
        <div class="flex items-center gap-2 mb-1">
          <Icon
            :icon="modeIcon"
            :class="['w-4 h-4', modeIconColor]"
          />
          <span
            :class="[
              'px-2 py-0.5 rounded-full text-xs font-medium',
              modeClass,
            ]"
          >
            {{ modeLabel }}
          </span>
        </div>
        <p class="text-xs txt-secondary font-mono truncate">
          {{ session.sessionIdDisplay || session.sessionId }}
        </p>
      </div>
      <div class="flex items-center gap-1 flex-shrink-0">
        <span
          v-if="session.isExpired"
          class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-500/10 text-gray-600 dark:text-gray-400"
        >
          {{ $t('widgetSessions.expired') }}
        </span>
      </div>
    </div>

    <!-- Preview -->
    <div v-if="session.lastMessagePreview" class="mb-3 p-2 surface-chip rounded-lg">
      <p class="text-xs txt-secondary line-clamp-2">
        {{ session.lastMessagePreview }}
      </p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-2 mb-3 text-center">
      <div class="p-2 surface-chip rounded-lg">
        <p class="text-xs txt-secondary">{{ $t('widgetSessions.messages') }}</p>
        <p class="text-sm font-bold txt-primary">{{ session.messageCount }}</p>
      </div>
      <div class="p-2 surface-chip rounded-lg">
        <p class="text-xs txt-secondary">{{ $t('widgetSessions.files') }}</p>
        <p class="text-sm font-bold txt-primary">{{ session.fileCount }}</p>
      </div>
      <div class="p-2 surface-chip rounded-lg">
        <p class="text-xs txt-secondary">{{ $t('widgetSessions.lastActivity') }}</p>
        <p class="text-sm font-bold txt-primary">{{ timeAgo }}</p>
      </div>
    </div>

    <!-- Footer -->
    <div class="flex items-center justify-between text-xs txt-secondary">
      <span>{{ $t('widgetSessions.created') }}: {{ formatDate(session.created) }}</span>
      <div class="flex items-center gap-2" @click.stop>
        <button
          v-if="session.mode === 'ai' && !session.isExpired"
          class="px-3 py-1.5 rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 font-medium transition-colors"
          @click="$emit('takeover', session)"
        >
          <Icon icon="heroicons:hand-raised" class="w-3.5 h-3.5 inline mr-1" />
          {{ $t('widgetSessions.takeOver') }}
        </button>
        <button
          v-else-if="session.mode === 'waiting' && !session.isExpired"
          class="px-3 py-1.5 rounded-lg bg-yellow-500/10 hover:bg-yellow-500/20 text-yellow-600 dark:text-yellow-400 font-medium transition-colors"
          @click="$emit('takeover', session)"
        >
          <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-3.5 h-3.5 inline mr-1" />
          {{ $t('widgetSessions.respond') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { WidgetSession } from '@/services/api/widgetSessionsApi'

const props = defineProps<{
  session: WidgetSession
  widgetId: string
}>()

defineEmits<{
  (e: 'view', session: WidgetSession): void
  (e: 'takeover', session: WidgetSession): void
}>()

const { t } = useI18n()

const modeIcon = computed(() => {
  switch (props.session.mode) {
    case 'ai':
      return 'heroicons:cpu-chip'
    case 'human':
      return 'heroicons:user'
    case 'waiting':
      return 'heroicons:clock'
    default:
      return 'heroicons:question-mark-circle'
  }
})

const modeIconColor = computed(() => {
  switch (props.session.mode) {
    case 'ai':
      return 'text-blue-500'
    case 'human':
      return 'text-green-500'
    case 'waiting':
      return 'text-yellow-500'
    default:
      return 'txt-secondary'
  }
})

const modeClass = computed(() => {
  switch (props.session.mode) {
    case 'ai':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400'
    case 'human':
      return 'bg-green-500/10 text-green-600 dark:text-green-400'
    case 'waiting':
      return 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400'
    default:
      return 'bg-gray-500/10 text-gray-600 dark:text-gray-400'
  }
})

const modeLabel = computed(() => {
  switch (props.session.mode) {
    case 'ai':
      return t('widgetSessions.modeAi')
    case 'human':
      return t('widgetSessions.modeHuman')
    case 'waiting':
      return t('widgetSessions.modeWaiting')
    default:
      return props.session.mode
  }
})

const timeAgo = computed(() => {
  if (!props.session.lastMessage) return '-'

  const now = Math.floor(Date.now() / 1000)
  const diff = now - props.session.lastMessage

  if (diff < 60) return t('common.justNow')
  if (diff < 3600) return t('common.minutesAgo', { count: Math.floor(diff / 60) })
  if (diff < 86400) return t('common.hoursAgo', { count: Math.floor(diff / 3600) })
  return t('common.daysAgo', { count: Math.floor(diff / 86400) })
})

const formatDate = (timestamp: number) => {
  return new Date(timestamp * 1000).toLocaleDateString()
}
</script>
