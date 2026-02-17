<template>
  <div class="flex flex-col h-full w-full max-w-full overflow-x-hidden">
    <!-- Toolbar -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-4 gap-3">
      <!-- Search & Filters -->
      <div class="grid grid-cols-1 md:flex md:items-center gap-3 flex-1 min-w-0 w-full max-w-full">
        <div class="relative w-full md:flex-1 md:max-w-md min-w-0 max-w-full">
          <Icon
            icon="mdi:magnify"
            class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 txt-secondary"
          />
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="$t('memories.search.placeholder')"
            class="w-full max-w-full min-w-0 pl-10 pr-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50"
          />
        </div>
        <select
          v-model="filterValue"
          class="w-full md:w-auto max-w-full min-w-0 px-4 py-2.5 rounded-lg surface-chip txt-primary cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand/50"
        >
          <option value="">{{ $t('memories.listView.allMemories') }}</option>

          <!-- Categories -->
          <optgroup :label="$t('memories.listView.categories')">
            <option
              v-for="cat in availableCategories"
              :key="'cat-' + cat.category"
              :value="'category:' + cat.category"
            >
              {{ $t(`memories.categories.${cat.category}`, cat.category) }} ({{ cat.count }})
            </option>
          </optgroup>

          <!-- Keys -->
          <optgroup :label="$t('memories.listView.keys')">
            <option
              v-for="keyItem in availableKeys"
              :key="'key-' + keyItem.key"
              :value="'key:' + keyItem.key"
            >
              {{ keyItem.key }} ({{ keyItem.count }})
            </option>
          </optgroup>
        </select>
        <select
          v-model="sortBy"
          class="w-full md:w-auto max-w-full min-w-0 px-4 py-2.5 rounded-lg surface-chip txt-primary cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand/50"
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
        <button class="btn-primary px-4 py-2.5 rounded-lg" @click="$emit('create')">
          <Icon icon="mdi:plus" class="w-4 h-4 inline mr-1" />
          {{ $t('memories.createButton') }}
        </button>
      </div>
    </div>

    <!-- Memory Table (desktop) -->
    <div class="flex-1 md:overflow-auto md:scroll-thin">
      <table class="w-full hidden md:table">
        <thead class="sticky top-0 bg-surface-card backdrop-blur-xl z-10">
          <tr class="border-b border-light-border/30 dark:border-dark-border/20">
            <th class="p-3 text-left w-12">
              <input
                type="checkbox"
                :checked="isAllSelected"
                class="checkbox-brand"
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
            :data-memory-id="memory.id"
            class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-surface-soft transition-colors cursor-pointer"
            :class="{ 'bg-brand-500/10': isSelected(memory.id) }"
            @click="toggleSelect(memory.id)"
          >
            <td class="p-3">
              <input
                type="checkbox"
                :checked="isSelected(memory.id)"
                class="checkbox-brand"
                @change="toggleSelect(memory.id)"
                @click.stop
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

      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 pb-2">
        <div
          v-for="memory in filteredMemories"
          :key="memory.id"
          :data-memory-id="memory.id"
          class="surface-card rounded-xl p-4 cursor-pointer"
          :class="isSelected(memory.id) ? 'ring-2 ring-brand' : ''"
          @click="toggleSelect(memory.id)"
        >
          <div class="flex items-start gap-3">
            <input
              type="checkbox"
              class="checkbox-brand mt-1"
              :checked="isSelected(memory.id)"
              @change="toggleSelect(memory.id)"
              @click.stop
            />
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 flex-wrap mb-1">
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  :style="{
                    backgroundColor: getCategoryColor(memory.category) + '20',
                    color: getCategoryColor(memory.category),
                  }"
                >
                  {{ $t(`memories.categories.${memory.category}`, memory.category) }}
                </span>
                <span class="txt-primary font-semibold truncate max-w-[12rem]">{{
                  memory.key
                }}</span>
              </div>
              <div class="txt-secondary text-sm leading-snug">
                {{ memory.value }}
              </div>
              <div class="flex items-center justify-between mt-3 gap-2">
                <div class="text-xs txt-tertiary">
                  {{ formatTimestamp(memory.updated) }}
                </div>
                <div class="flex items-center gap-1">
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
              </div>
            </div>
          </div>
        </div>
      </div>

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
const filterValue = ref('') // Format: 'category:xyz' or 'key:xyz'
const selectedMemories = ref<number[]>([])
const sortBy = ref<'category' | 'key' | 'updated'>('category')

// Available keys for filtering
const availableKeys = computed(() => {
  const keysMap = new Map<string, number>()
  props.memories.forEach((m) => {
    keysMap.set(m.key, (keysMap.get(m.key) || 0) + 1)
  })
  return Array.from(keysMap.entries())
    .map(([key, count]) => ({ key, count }))
    .sort((a, b) => a.key.localeCompare(b.key))
})

const filteredMemories = computed(() => {
  let filtered = props.memories

  // Parse filter value
  if (filterValue.value) {
    const [type, value] = filterValue.value.split(':')
    if (type === 'category') {
      filtered = filtered.filter((m) => m.category === value)
    } else if (type === 'key') {
      filtered = filtered.filter((m) => m.key === value)
    }
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
