<template>
  <div data-testid="comp-widget-list">
    <div class="mb-6 flex items-center justify-between" data-testid="section-header">
      <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
        <ListBulletIcon class="w-5 h-5" />
        Your Widgets
      </h2>
      <button
        @click="$emit('create')"
        class="btn-primary px-4 py-2 rounded-lg flex items-center gap-2"
        data-testid="btn-create"
      >
        <PlusIcon class="w-5 h-5" />
        Create New Widget
      </button>
    </div>

    <div v-if="widgets.length === 0" class="surface-card p-12 text-center" data-testid="section-empty">
      <div class="txt-secondary mb-4">
        No widgets created yet. Click "Create New Widget" to get started.
      </div>
    </div>

    <div v-else class="space-y-4" data-testid="section-widget-list">
      <div
        v-for="widget in widgets"
        :key="widget.id"
        class="surface-card p-6 hover:shadow-lg transition-shadow cursor-pointer"
        @click="$emit('edit', widget)"
        data-testid="item-widget"
      >
        <div class="flex items-start gap-6">
          <!-- Left: Icon + Title -->
          <div class="flex items-center gap-4 min-w-[300px] flex-shrink-0">
            <div 
              class="w-16 h-16 rounded-full flex items-center justify-center shadow-md flex-shrink-0"
              :style="{ backgroundColor: widget.primaryColor }"
            >
              <ChatBubbleLeftRightIcon 
                class="w-8 h-8"
                :style="{ color: widget.iconColor }"
              />
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="text-lg font-semibold txt-primary truncate">
                Widget #{{ widget.id }}
              </h3>
              <p class="txt-secondary truncate">
                {{ getIntegrationType(widget.integrationType) }}
              </p>
            </div>
          </div>

          <!-- Middle: Info -->
          <div class="flex-1 min-w-0">
            <div class="grid grid-cols-2 gap-x-8 gap-y-2.5">
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full bg-[var(--brand)] flex-shrink-0"></div>
                <span class="txt-secondary truncate">{{ getPosition(widget.position) }}</span>
              </div>
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="widget.autoOpen ? 'bg-green-500' : 'bg-gray-400'"></div>
                <span class="txt-secondary truncate">{{ widget.autoOpen ? 'Auto-open' : 'Manual' }}</span>
              </div>
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full bg-cyan-500 flex-shrink-0"></div>
                <span class="txt-secondary truncate">{{ widget.aiPrompt }}</span>
              </div>
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="widget.defaultTheme === 'dark' ? 'bg-gray-700' : 'bg-gray-200'"></div>
                <span class="txt-secondary capitalize truncate">{{ widget.defaultTheme || 'light' }}</span>
              </div>
            </div>
            
            <div v-if="widget.autoMessage" class="mt-3 p-3 bg-black/5 dark:bg-white/5 rounded">
              <p class="text-sm txt-primary line-clamp-2">{{ widget.autoMessage }}</p>
            </div>
          </div>

          <!-- Right: Actions -->
          <div class="flex gap-2 min-w-[180px] flex-shrink-0">
            <button
              @click.stop="$emit('edit', widget)"
              class="flex-1 px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-medium"
              data-testid="btn-edit"
            >
              <PencilIcon class="w-4 h-4 inline mr-1.5" />
              Edit
            </button>
            <button
              @click.stop="$emit('delete', widget.id)"
              class="px-4 py-2 rounded-lg border border-red-500/30 text-red-500 hover:bg-red-500/10 transition-colors"
              :aria-label="`Delete widget ${widget.id}`"
              data-testid="btn-delete"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ListBulletIcon, PlusIcon, PencilIcon, TrashIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import type { Widget } from '@/mocks/widgets'
import { integrationTypes, positions } from '@/mocks/widgets'

interface Props {
  widgets: Widget[]
}

defineProps<Props>()

defineEmits<{
  create: []
  edit: [widget: Widget]
  delete: [widgetId: string]
}>()

const getIntegrationType = (type: string) => {
  return integrationTypes.find(t => t.value === type)?.label || type
}

const getPosition = (pos: string) => {
  return positions.find(p => p.value === pos)?.label || pos
}
</script>
