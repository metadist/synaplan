<template>
  <div class="space-y-4">
    <!-- Header with selection info and actions -->
    <div class="flex flex-col gap-3">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold txt-primary">{{ $t('mail.savedHandlers') }}</h2>
          <p class="text-sm txt-secondary mt-1">{{ $t('mail.savedHandlersDesc') }}</p>
        </div>
        <button
          class="btn-primary px-4 py-2 rounded-lg flex items-center gap-2"
          @click="$emit('create')"
        >
          <PlusIcon class="w-5 h-5" />
          {{ $t('mail.createHandler') }}
        </button>
      </div>

      <!-- Bulk actions bar (only show when handlers are selected) -->
      <transition
        enter-active-class="transition-all duration-200"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition-all duration-150"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-2"
      >
        <div
          v-if="selectedHandlers.length > 0"
          class="surface-card p-3 rounded-lg border-2 border-[var(--brand)]/20 flex items-center justify-between gap-3"
        >
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-[var(--brand)]/10 flex items-center justify-center">
              <span class="text-sm font-semibold text-[var(--brand)]">{{
                selectedHandlers.length
              }}</span>
            </div>
            <span class="text-sm font-medium txt-primary">
              {{ $t('mail.selectedCount', { count: selectedHandlers.length }) }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <button
              class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors bg-green-500/10 text-green-600 dark:text-green-400 hover:bg-green-500/20 flex items-center gap-1.5"
              @click="activateSelected"
            >
              <CheckCircleIcon class="w-4 h-4" />
              {{ $t('mail.activate') }}
            </button>
            <button
              class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors bg-gray-500/10 txt-secondary hover:bg-gray-500/20 flex items-center gap-1.5"
              @click="deactivateSelected"
            >
              <XCircleIcon class="w-4 h-4" />
              {{ $t('mail.deactivate') }}
            </button>
            <button
              class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 flex items-center gap-1.5"
              @click="deleteSelected"
            >
              <TrashIcon class="w-4 h-4" />
              {{ $t('mail.deleteSelected') }}
            </button>
            <button
              class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary"
              :aria-label="$t('common.cancel')"
              @click="selectedHandlers = []"
            >
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
      </transition>
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
        :class="[
          'surface-card p-5 hover:shadow-lg transition-shadow cursor-pointer group relative',
          selectedHandlers.includes(handler.id) && 'ring-2 ring-[var(--brand)]',
        ]"
        @click="handleCardClick($event, handler)"
      >
        <!-- Selection Checkbox -->
        <div class="absolute top-3 right-3 z-10 flex items-center gap-2">
          <label class="relative flex items-center cursor-pointer" @click.stop>
            <input
              v-model="selectedHandlers"
              type="checkbox"
              :value="handler.id"
              class="peer sr-only"
            />
            <div
              class="w-5 h-5 rounded border-2 border-light-border dark:border-dark-border peer-checked:border-[var(--brand)] peer-checked:bg-[var(--brand)] transition-all flex items-center justify-center"
            >
              <CheckIcon
                class="w-3 h-3 text-white opacity-0 peer-checked:opacity-100 transition-opacity"
              />
            </div>
          </label>
        </div>
        <div class="flex items-start justify-between mb-3 pr-8">
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
        </div>

        <!-- Delete button (separate from header to avoid overlap) -->
        <button
          class="absolute top-3 right-10 icon-ghost icon-ghost--danger opacity-0 group-hover:opacity-100 transition-all z-20"
          :aria-label="$t('mail.deleteHandler')"
          @click.stop="$emit('delete', handler.id)"
        >
          <TrashIcon class="w-4 h-4" />
        </button>

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
import { ref } from 'vue'
import {
  EnvelopeIcon,
  PlusIcon,
  TrashIcon,
  ServerIcon,
  UserGroupIcon,
  ClockIcon,
  CheckCircleIcon,
  XCircleIcon,
  CheckIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { SavedMailHandler } from '@/services/api/inboundEmailHandlersApi'

interface Props {
  handlers: SavedMailHandler[]
}

defineProps<Props>()

const emit = defineEmits<{
  create: []
  edit: [handler: SavedMailHandler]
  delete: [id: string]
  bulkUpdateStatus: [handlerIds: string[], status: 'active' | 'inactive']
  bulkDelete: [handlerIds: string[]]
}>()

const selectedHandlers = ref<string[]>([])

const handleCardClick = (event: MouseEvent, handler: SavedMailHandler) => {
  // Only emit edit if not clicking on checkbox
  if (!(event.target as HTMLElement).closest('input[type="checkbox"]')) {
    emit('edit', handler)
  }
}

const activateSelected = () => {
  if (selectedHandlers.value.length > 0) {
    emit('bulkUpdateStatus', selectedHandlers.value, 'active')
    selectedHandlers.value = []
  }
}

const deactivateSelected = () => {
  if (selectedHandlers.value.length > 0) {
    emit('bulkUpdateStatus', selectedHandlers.value, 'inactive')
    selectedHandlers.value = []
  }
}

const deleteSelected = () => {
  if (selectedHandlers.value.length > 0) {
    emit('bulkDelete', selectedHandlers.value)
    selectedHandlers.value = []
  }
}

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
