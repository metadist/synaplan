<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-2 md:p-4 lg:p-8">
      <div class="max-w-7xl mx-auto h-full flex flex-col">
        <!-- Header -->
        <div
          class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-0 mb-4 md:mb-6"
        >
          <h1 class="text-2xl md:text-3xl font-bold txt-primary">
            {{ $t('pageTitles.memories') }}
          </h1>

          <!-- View Toggle -->
          <div class="flex items-center gap-2 surface-chip p-1 rounded-lg w-full sm:w-auto">
            <button
              class="flex-1 sm:flex-none px-3 md:px-4 py-2 rounded-md transition-colors text-sm"
              :class="
                viewMode === 'list' ? 'bg-brand-500 text-white' : 'txt-secondary hover:txt-primary'
              "
              @click="viewMode = 'list'"
            >
              <Icon icon="mdi:format-list-bulleted" class="w-4 h-4 md:w-5 md:h-5 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('memories.listView.title') }}</span>
              <span class="sm:hidden">Liste</span>
            </button>
            <button
              class="flex-1 sm:flex-none px-3 md:px-4 py-2 rounded-md transition-colors text-sm"
              :class="
                viewMode === 'graph' ? 'bg-brand-500 text-white' : 'txt-secondary hover:txt-primary'
              "
              @click="viewMode = 'graph'"
            >
              <Icon icon="mdi:graph" class="w-4 h-4 md:w-5 md:h-5 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('memories.graphView.title') }}</span>
              <span class="sm:hidden">Graph</span>
            </button>
          </div>
        </div>

        <!-- Views -->
        <div
          class="flex-1 surface-card rounded-xl p-3 md:p-6 overflow-hidden"
          style="min-height: 500px; max-height: calc(100vh - 200px)"
        >
          <MemoryListView
            v-if="viewMode === 'list'"
            :memories="memoriesStore.memories"
            :available-categories="availableCategories"
            @edit="handleEdit"
            @delete="handleDelete"
            @bulk-delete="handleBulkDelete"
            @create="handleCreate"
          />
          <MemoryGraphView
            v-else
            class="w-full h-full"
            :memories="memoriesStore.memories"
            :available-categories="availableCategories"
            @edit="handleEdit"
            @delete="handleDelete"
          />
        </div>
      </div>
    </div>

    <!-- Edit/Create Dialog -->
    <MemoryFormDialog
      :is-open="isFormDialogOpen"
      :memory="currentMemoryToEdit"
      :available-categories="availableCategories.map((c) => c.category)"
      @close="handleFormDialogClose"
      @save="handleSaveMemory"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import MemoryListView from '@/components/MemoryListView.vue'
import MemoryGraphView from '@/components/MemoryGraphView.vue'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'
import { useMemoriesStore } from '@/stores/userMemories'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { getCategories } from '@/services/api/userMemoriesApi'
import type {
  UserMemory,
  CreateMemoryRequest,
  UpdateMemoryRequest,
} from '@/services/api/userMemoriesApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const memoriesStore = useMemoriesStore()
const { success, error } = useNotification()
const { confirm } = useDialog()

const viewMode = ref<'list' | 'graph'>('list')
const availableCategories = ref<Array<{ category: string; count: number }>>([])
const isFormDialogOpen = ref(false)
const currentMemoryToEdit = ref<UserMemory | null>(null)

onMounted(async () => {
  await memoriesStore.init()
  availableCategories.value = await getCategories()

  // Check if we should open edit dialog from query params
  if (route.query.edit) {
    const memoryId = parseInt(route.query.edit as string)
    const memory = memoriesStore.memories.find((m) => m.id === memoryId)
    if (memory) {
      handleEdit(memory)
    }
  }
})

function handleCreate() {
  currentMemoryToEdit.value = null
  isFormDialogOpen.value = true
}

function handleEdit(memory: UserMemory) {
  currentMemoryToEdit.value = memory
  isFormDialogOpen.value = true
}

async function handleDelete(memory: UserMemory) {
  const confirmed = await confirm({
    title: t('memories.deleteConfirm.title'),
    message: t('memories.deleteConfirm.message'),
    confirmText: t('memories.deleteConfirm.confirm'),
    danger: true,
  })

  if (confirmed) {
    await memoriesStore.removeMemory(memory.id)
    if (!memoriesStore.error) {
      success(t('memories.deleteSuccess'))
      await loadMemories()
    } else {
      error(t('memories.deleteError'))
    }
  }
}

async function handleBulkDelete(memoryIds: number[]) {
  const confirmed = await confirm({
    title: t('memories.bulkDeleteConfirm.title'),
    message: t('memories.bulkDeleteConfirm.message', { count: memoryIds.length }),
    confirmText: t('memories.deleteConfirm.confirm'),
    danger: true,
  })

  if (confirmed) {
    for (const id of memoryIds) {
      await memoriesStore.removeMemory(id)
    }
    if (!memoriesStore.error) {
      success(t('memories.bulkDeleteSuccess', { count: memoryIds.length }))
      await loadMemories()
    } else {
      error(t('memories.bulkDeleteError'))
    }
  }
}

async function handleSaveMemory(memoryData: CreateMemoryRequest | UpdateMemoryRequest) {
  if (currentMemoryToEdit.value) {
    // For updates, we only need the value field
    await memoriesStore.editMemory(currentMemoryToEdit.value.id, {
      value: memoryData.value,
    } as UpdateMemoryRequest)
    if (!memoriesStore.error) {
      success(t('memories.editSuccess'))
    } else {
      error(t('memories.editError'))
    }
  } else {
    // For creates, we need category, key, and value
    await memoriesStore.addMemory(memoryData as CreateMemoryRequest)
    if (!memoriesStore.error) {
      success(t('memories.createSuccess'))
    } else {
      error(t('memories.createError'))
    }
  }
  isFormDialogOpen.value = false
  await loadMemories()
}

function handleFormDialogClose() {
  isFormDialogOpen.value = false
  currentMemoryToEdit.value = null
  // Remove edit query param if present
  if (route.query.edit) {
    router.replace({ query: {} })
  }
}

async function loadMemories() {
  await memoriesStore.fetchMemories()
  availableCategories.value = await getCategories()
}
</script>
