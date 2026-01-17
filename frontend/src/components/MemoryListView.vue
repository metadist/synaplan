<template>
  <div class="flex flex-col h-full">
    <!-- Toolbar -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
      <!-- Search & Filters -->
      <div class="flex items-center gap-3 flex-1 min-w-0">
        <div class="relative flex-1 max-w-md">
          <Icon
            icon="mdi:magnify"
            class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 txt-secondary"
          />
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="$t('memories.search')"
            class="w-full pl-10 pr-4 py-2.5 surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50"
          />
        </div>
        <select
          v-model="selectedCategory"
          class="px-4 py-2.5 surface-chip txt-primary cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand/50"
        >
          <option value="">{{ $t('memories.allCategories') }}</option>
          <option v-for="cat in availableCategories" :key="cat.category" :value="cat.category">
            {{ $t(`memories.categories.${cat.category}`, cat.category) }} ({{ cat.count }})
          </option>
        </select>
        <select
          v-model="sortBy"
          class="px-4 py-2.5 surface-chip txt-primary cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand/50"
        >
          <option value="category">{{ $t('memories.listView.sortByCategory') }}</option>
          <option value="key">{{ $t('memories.listView.sortByKey') }}</option>
          <option value="updated">{{ $t('memories.listView.sortByDate') }}</option>
        </select>
      </div>

      <!-- Bulk Actions -->
      <div v-if="selectedMemories.length > 0" class="flex items-center gap-2">
        <span class="text-sm txt-secondary"
          >{{ selectedMemories.length }} {{ $t('memories.selected') }}</span
        >
        <button
          class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors"
          @click="bulkDelete"
        >
          <Icon icon="mdi:delete" class="w-4 h-4 inline mr-1" />
          {{ $t('common.delete') }}
        </button>
        <button
          class="px-4 py-2 rounded-lg bg-gray-500 text-white hover:bg-gray-600 transition-colors"
          @click="clearSelection"
        >
          {{ $t('common.cancel') }}
        </button>
      </div>

      <!-- View Actions -->
      <div v-else class="flex items-center gap-2">
        <button
          class="px-4 py-2 rounded-lg surface-chip txt-primary hover:bg-opacity-80 transition-colors"
          @click="selectAll"
        >
          <Icon icon="mdi:checkbox-multiple-marked" class="w-4 h-4 inline mr-1" />
          {{ $t('memories.selectAll') }}
        </button>
        <button class="btn-primary px-4 py-2.5" @click="$emit('create')">
          <Icon icon="mdi:plus" class="w-4 h-4 inline mr-1" />
          {{ $t('memories.createButton') }}
        </button>
      </div>
    </div>

    <!-- Memory Table -->
    <div class="flex-1 overflow-auto scroll-thin">
      <table class="w-full">
        <thead class="sticky top-0 bg-surface-card backdrop-blur-xl z-10">
          <tr class="border-b border-light-border/30 dark:border-dark-border/20">
            <th class="p-3 text-left w-12">
              <input
                type="checkbox"
                :checked="isAllSelected"
                class="w-4 h-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                @change="toggleSelectAll"
              />
            </th>
            <th class="p-3 text-left txt-primary font-semibold text-sm">
              {{ $t('memories.category') }}
            </th>
            <th class="p-3 text-left txt-primary font-semibold text-sm">
              {{ $t('memories.key') }}
            </th>
            <th class="p-3 text-left txt-primary font-semibold text-sm">
              {{ $t('memories.value') }}
            </th>
            <th class="p-3 text-left txt-primary font-semibold text-sm">
              {{ $t('memories.listView.source') }}
            </th>
            <th class="p-3 text-left txt-primary font-semibold text-sm">
              {{ $t('memories.updated') }}
            </th>
            <th class="p-3 text-right txt-primary font-semibold text-sm w-32">
              {{ $t('common.actions') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="memory in filteredMemories"
            :key="memory.id"
            class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-surface-soft transition-colors"
            :class="{ 'bg-brand-500/10': isSelected(memory.id) }"
          >
            <td class="p-3">
              <input
                type="checkbox"
                :checked="isSelected(memory.id)"
                class="w-4 h-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                @change="toggleSelect(memory.id)"
              />
            </td>
            <td class="p-3">
              <span
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                :style="{
                  backgroundColor: getCategoryColor(memory.category) + '20',
                  color: getCategoryColor(memory.category),
                }"
              >
                {{ $t(`memories.categories.${memory.category}`, memory.category) }}
              </span>
            </td>
            <td class="p-3 txt-primary font-medium">{{ memory.key }}</td>
            <td class="p-3 txt-secondary text-sm max-w-xs truncate">{{ memory.value }}</td>
            <td class="p-3">
              <span class="text-xs txt-tertiary">
                {{ $t(`memories.source.${memory.source}`) }}
              </span>
            </td>
            <td class="p-3 text-xs txt-tertiary">
              {{ formatTimestamp(memory.updated) }}
            </td>
            <td class="p-3">
              <div class="flex items-center justify-end gap-1">
                <button
                  class="p-2 rounded-lg hover:bg-brand-500/10 txt-brand transition-colors"
                  :title="$t('common.edit')"
                  @click="$emit('edit', memory)"
                >
                  <Icon icon="mdi:pencil" class="w-4 h-4" />
                </button>
                <button
                  class="p-2 rounded-lg hover:bg-red-500/10 text-red-500 transition-colors"
                  :title="$t('common.delete')"
                  @click="$emit('delete', memory)"
                >
                  <Icon icon="mdi:delete" class="w-4 h-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Empty State -->
      <div v-if="filteredMemories.length === 0" class="text-center py-12">
        <Icon icon="mdi:brain-off" class="w-16 h-16 mx-auto txt-secondary mb-4" />
        <p class="txt-secondary text-lg">{{ $t('memories.noResults') }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  memories: UserMemory[]
  availableCategories: Array<{ category: string; count: number }>
}

interface Emits {
  (e: 'edit', memory: UserMemory): void
  (e: 'delete', memory: UserMemory): void
  (e: 'bulk-delete', memoryIds: number[]): void
  (e: 'create'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const searchQuery = ref('')
const selectedCategory = ref('')
const selectedMemories = ref<number[]>([])
const sortBy = ref<'category' | 'key' | 'updated'>('category')

const filteredMemories = computed(() => {
  let filtered = props.memories

  if (selectedCategory.value) {
    filtered = filtered.filter((m) => m.category === selectedCategory.value)
  }

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(
      (m) =>
        m.key.toLowerCase().includes(query) ||
        m.value.toLowerCase().includes(query) ||
        m.category.toLowerCase().includes(query)
    )
  }

  // Sort based on sortBy value
  const sorted = [...filtered]
  if (sortBy.value === 'category') {
    sorted.sort((a, b) => {
      if (a.category !== b.category) {
        return a.category.localeCompare(b.category)
      }
      return a.key.localeCompare(b.key)
    })
  } else if (sortBy.value === 'key') {
    sorted.sort((a, b) => a.key.localeCompare(b.key))
  } else if (sortBy.value === 'updated') {
    sorted.sort((a, b) => b.updated - a.updated) // Newest first
  }

  return sorted
})

const isAllSelected = computed(() => {
  return (
    filteredMemories.value.length > 0 &&
    filteredMemories.value.every((m) => selectedMemories.value.includes(m.id))
  )
})

function isSelected(id: number): boolean {
  return selectedMemories.value.includes(id)
}

function toggleSelect(id: number) {
  const index = selectedMemories.value.indexOf(id)
  if (index > -1) {
    selectedMemories.value.splice(index, 1)
  } else {
    selectedMemories.value.push(id)
  }
}

function toggleSelectAll() {
  if (isAllSelected.value) {
    selectedMemories.value = []
  } else {
    selectedMemories.value = filteredMemories.value.map((m) => m.id)
  }
}

function selectAll() {
  selectedMemories.value = filteredMemories.value.map((m) => m.id)
}

function clearSelection() {
  selectedMemories.value = []
}

function bulkDelete() {
  emit('bulk-delete', [...selectedMemories.value])
  selectedMemories.value = []
}

function formatTimestamp(timestamp: number) {
  const date = new Date(timestamp * 1000)
  return (
    date.toLocaleDateString() +
    ' ' +
    date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  )
}

const categoryColors: Record<string, string> = {
  preferences: '#3b82f6',
  personal: '#10b981',
  work: '#f59e0b',
  projects: '#8b5cf6',
  default: '#6366f1',
}

function getCategoryColor(category: string): string {
  return categoryColors[category] || categoryColors.default
}
</script>
