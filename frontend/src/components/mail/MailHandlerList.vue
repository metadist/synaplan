<template>
  <div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold txt-primary">{{ $t('mail.savedHandlers') }}</h2>
        <p class="text-sm txt-secondary mt-1">{{ $t('mail.savedHandlersDesc') }}</p>
      </div>
      <button
        class="btn-primary px-4 py-2 rounded-lg flex items-center gap-2 w-full sm:w-auto"
        @click="$emit('create')"
      >
        <PlusIcon class="w-5 h-5" />
        {{ $t('mail.createHandler') }}
      </button>
    </div>

    <div v-if="handlers.length === 0" class="surface-card p-12 text-center">
      <EnvelopeIcon class="w-16 h-16 mx-auto mb-4 txt-secondary opacity-30" />
      <h3 class="text-lg font-semibold txt-primary mb-2">{{ $t('mail.noHandlers') }}</h3>
      <p class="txt-secondary mb-6">{{ $t('mail.noHandlersDesc') }}</p>
      <button
        class="btn-primary px-6 py-2 rounded-lg inline-flex items-center gap-2"
        @click="$emit('create')"
      >
        <PlusIcon class="w-5 h-5" />
        {{ $t('mail.createFirst') }}
      </button>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div
        v-for="handler in handlers"
        :key="handler.id"
        class="surface-card p-5 hover:shadow-lg transition-shadow cursor-pointer group"
        @click="$emit('edit', handler)"
      >
        <div class="flex items-start justify-between mb-3">
          <div class="flex items-center gap-3 flex-1 min-w-0">
            <div
              :class="[
                'w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0',
                handler.status === 'active'
                  ? 'bg-green-500/10'
                  : handler.status === 'error'
                    ? 'bg-red-500/10'
                    : 'bg-gray-500/10',
              ]"
            >
              <EnvelopeIcon
                :class="[
                  'w-6 h-6',
                  handler.status === 'active'
                    ? 'text-green-500 dark:text-green-400'
                    : handler.status === 'error'
                      ? 'text-red-500 dark:text-red-400'
                      : 'txt-secondary',
                ]"
              />
            </div>
            <div class="flex-1 min-w-0">
              <h3
                class="text-base font-semibold txt-primary truncate group-hover:text-[var(--brand)] transition-colors"
              >
                {{ handler.name }}
              </h3>
              <p class="text-xs txt-secondary truncate">{{ handler.config.username }}</p>
            </div>
          </div>
          <button
            class="icon-ghost icon-ghost--danger opacity-0 group-hover:opacity-100 transition-all"
            :aria-label="$t('mail.deleteHandler')"
            @click.stop="$emit('delete', handler.id)"
          >
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>

        <div class="space-y-2 mb-3">
          <div class="flex items-center gap-2 text-xs">
            <ServerIcon class="w-4 h-4 txt-secondary" />
            <span class="txt-secondary">{{ handler.config.mailServer }}</span>
          </div>
          <div class="flex items-center gap-2 text-xs">
            <UserGroupIcon class="w-4 h-4 txt-secondary" />
            <span class="txt-secondary"
              >{{ handler.departments.length }} {{ $t('mail.departments') }}</span
            >
          </div>
          <div class="flex items-center gap-2 text-xs">
            <ClockIcon class="w-4 h-4 txt-secondary" />
            <span class="txt-secondary"
              >{{ $t('mail.checkEvery') }} {{ handler.config.checkInterval }}m</span
            >
          </div>
        </div>

        <div
          class="flex items-center justify-between pt-3 border-t border-light-border/30 dark:border-dark-border/20"
        >
          <div
            :class="[
              'flex items-center gap-1.5 text-xs font-medium',
              handler.status === 'active'
                ? 'text-green-500 dark:text-green-400'
                : handler.status === 'error'
                  ? 'text-red-500 dark:text-red-400'
                  : 'txt-secondary',
            ]"
          >
            <div
              :class="[
                'w-2 h-2 rounded-full',
                handler.status === 'active'
                  ? 'bg-green-500 dark:bg-green-400'
                  : handler.status === 'error'
                    ? 'bg-red-500 dark:bg-red-400'
                    : 'bg-gray-500 dark:bg-gray-400',
              ]"
            ></div>
            {{ $t(`mail.status.${handler.status}`) }}
          </div>
          <span v-if="handler.lastTested" class="text-xs txt-secondary">
            {{ $t('mail.lastTested') }}: {{ formatDate(handler.lastTested) }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {
  EnvelopeIcon,
  PlusIcon,
  TrashIcon,
  ServerIcon,
  UserGroupIcon,
  ClockIcon,
} from '@heroicons/vue/24/outline'
import type { SavedMailHandler } from '@/services/api/inboundEmailHandlersApi'

interface Props {
  handlers: SavedMailHandler[]
}

defineProps<Props>()

defineEmits<{
  create: []
  edit: [handler: SavedMailHandler]
  delete: [id: string]
}>()

const formatDate = (date: Date) => {
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))

  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days}d ago`

  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>
