<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMemoriesStore } from '@/stores/userMemories'
import MemoryCard from './MemoryCard.vue'
import MemoryEditDialog from './MemoryEditDialog.vue'
import { useDialog } from '@/composables/useDialog'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { Plus, Search } from 'lucide-vue-next'

const { t } = useI18n()
const memoriesStore = useMemoriesStore()
const { confirm } = useDialog()

const selectedCategory = ref<string>('all')
const searchQuery = ref('')
const editingMemory = ref<UserMemory | null>(null)
const showCreateDialog = ref(false)

const categories = computed(() => [
  { key: 'all', label: t('memories.categories.all'), count: memoriesStore.totalCount },
  {
    key: 'preferences',
    label: t('memories.categories.preferences'),
    count: memoriesStore.categoryCount('preferences'),
  },
  {
    key: 'personal',
    label: t('memories.categories.personal'),
    count: memoriesStore.categoryCount('personal'),
  },
  { key: 'work', label: t('memories.categories.work'), count: memoriesStore.categoryCount('work') },
  {
    key: 'projects',
    label: t('memories.categories.projects'),
    count: memoriesStore.categoryCount('projects'),
  },
])

const displayedMemories = computed(() => {
  let filtered =
    selectedCategory.value === 'all' ? memoriesStore.memories : memoriesStore.filteredMemories

  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(
      (m) =>
        m.key.toLowerCase().includes(query) ||
        m.value.toLowerCase().includes(query) ||
        m.category.toLowerCase().includes(query)
    )
  }

  return filtered
})

function selectCategory(category: string) {
  selectedCategory.value = category
  memoriesStore.selectCategory(category === 'all' ? null : category)
}

function handleEdit(memory: UserMemory) {
  editingMemory.value = memory
}

function handleCloseEdit() {
  editingMemory.value = null
}

function handleCreate() {
  showCreateDialog.value = true
}

function handleCloseCreate() {
  showCreateDialog.value = false
}

async function handleDelete(memory: UserMemory) {
  const confirmed = await confirm({
    title: t('memories.delete.title'),
    message: t('memories.delete.message'),
    confirmText: t('memories.delete.confirm'),
    danger: true,
  })

  if (confirmed) {
    await memoriesStore.removeMemory(memory.id)
  }
}
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header with Search -->
    <div class="mb-6">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="text-2xl font-bold txt-primary mb-1">
            {{ t('memories.title') }}
          </h2>
          <p class="text-sm txt-secondary">
            {{ t('memories.description') }}
          </p>
        </div>
        <button
          class="btn-primary px-4 py-2 rounded-lg font-medium flex items-center gap-2"
          @click="handleCreate"
        >
          <Plus :size="18" />
          {{ t('memories.actions.create') }}
        </button>
      </div>

      <!-- Search Bar -->
      <div class="relative">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 txt-secondary" :size="18" />
        <input
          v-model="searchQuery"
          type="text"
          class="w-full surface-card pl-10 pr-4 py-3 rounded-lg txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand"
          :placeholder="t('memories.search.placeholder')"
        />
      </div>
    </div>

    <!-- Category Tabs -->
    <div class="flex items-center gap-2 mb-6 overflow-x-auto pb-2 scroll-thin">
      <button
        v-for="category in categories"
        :key="category.key"
        class="pill whitespace-nowrap"
        :class="{ 'pill--active': selectedCategory === category.key }"
        @click="selectCategory(category.key)"
      >
        {{ category.label }}
        <span
          class="px-2 py-0.5 rounded-full text-xs font-medium"
          :class="
            selectedCategory === category.key
              ? 'bg-brand/20 txt-brand'
              : 'surface-chip txt-secondary'
          "
        >
          {{ category.count }}
        </span>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="memoriesStore.loading" class="flex-1 flex items-center justify-center">
      <div class="text-center">
        <div
          class="inline-block w-8 h-8 border-4 border-brand border-t-transparent rounded-full animate-spin mb-3"
        />
        <p class="txt-secondary">{{ t('common.loading') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="displayedMemories.length === 0" class="flex-1 flex items-center justify-center">
      <div class="text-center max-w-md">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-chip flex items-center justify-center">
          <Search class="txt-secondary" :size="32" />
        </div>
        <h3 class="text-lg font-semibold txt-primary mb-2">
          {{ searchQuery ? t('memories.search.noResults') : t('memories.empty') }}
        </h3>
        <p class="txt-secondary">
          {{ searchQuery ? '' : t('memories.emptyDesc') }}
        </p>
      </div>
    </div>

    <!-- Memory Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 pb-8">
      <MemoryCard
        v-for="memory in displayedMemories"
        :key="memory.id"
        :memory="memory"
        @edit="handleEdit"
        @delete="handleDelete"
      />
    </div>

    <!-- Edit Dialog -->
    <MemoryEditDialog v-if="editingMemory" :memory="editingMemory" @close="handleCloseEdit" />

    <!-- Create Dialog -->
    <MemoryEditDialog v-if="showCreateDialog" :memory="null" @close="handleCloseCreate" />
  </div>
</template>
