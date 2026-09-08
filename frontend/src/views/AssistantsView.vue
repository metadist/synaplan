<template>
  <MainLayout data-testid="view-assistants">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <PageHeader
        :title="headerTitle"
        :subtitle="builderMode ? currentName : undefined"
        icon="mdi:robot-outline"
      >
        <button
          v-if="!builderMode"
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
          data-testid="btn-create-assistant-header"
          @click="createAssistant"
        >
          {{ $t('assistants.create') }}
        </button>
        <button
          v-else
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-back-gallery"
          @click="router.push('/ai/assistants')"
        >
          {{ $t('assistants.title') }}
        </button>
      </PageHeader>

      <AssistantBuilder v-if="builderMode && store.current" @deleted="onDeleted" />
      <AssistantGallery
        v-else-if="!builderMode"
        @create="createAssistant"
        @start-chat="startChat"
        @clone="cloneAssistant"
        @edit="editAssistant"
        @delete="deleteAssistant"
      />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import AssistantGallery from '@/components/assistants/AssistantGallery.vue'
import AssistantBuilder from '@/components/assistants/AssistantBuilder.vue'
import { useAgentsStore } from '@/stores/agents'
import { useChatsStore } from '@/stores/chats'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { agentsApi } from '@/services/api/agentsApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useAgentsStore()
const chatsStore = useChatsStore()
const { confirm } = useDialog()
const { error, success } = useNotification()

const builderMode = computed(() => route.name === 'ai-assistant-builder')
const headerTitle = computed(() =>
  builderMode.value ? t('pageTitles.assistantBuilder') : t('assistants.title')
)
const currentName = computed(() => store.current?.name ?? '')

async function openBuilder(id: string): Promise<void> {
  if (id === 'new') {
    try {
      const agent = await store.create(t('assistants.untitled'))
      await router.replace({ name: 'ai-assistant-builder', params: { id: String(agent.id) } })
    } catch {
      error(t('assistants.createFailed'))
    }
    return
  }
  const numericId = Number(id)
  if (!Number.isFinite(numericId) || numericId < 1) {
    await router.replace({ name: 'ai-assistants' })
    return
  }
  try {
    await store.load(numericId)
  } catch {
    error(t('assistants.loadFailed'))
    await router.replace({ name: 'ai-assistants' })
  }
}

async function createAssistant(): Promise<void> {
  await router.push({ name: 'ai-assistant-builder', params: { id: 'new' } })
}

function editAssistant(id: number): void {
  void router.push({ name: 'ai-assistant-builder', params: { id: String(id) } })
}

async function startChat(id: number): Promise<void> {
  try {
    if (chatsStore.chats.length === 0) {
      await chatsStore.loadChats()
    }
    await chatsStore.findOrCreateEmptyChat()
  } catch {
    error(t('assistants.startChatFailed'))
    return
  }
  await router.push({ name: 'chat', query: { agentId: String(id) } })
}

async function onDeleted(): Promise<void> {
  await router.push({ name: 'ai-assistants' })
}

async function deleteAssistant(id: number): Promise<void> {
  const card = store.gallery.find((row) => row.id === id)
  const published = card?.status === 'published'
  const ok = await confirm({
    title: t('assistants.delete'),
    message: published
      ? t('assistants.deletePublishedConfirm')
      : card?.status === 'archived'
        ? t('assistants.deleteArchivedConfirm')
        : t('assistants.deleteConfirm'),
    danger: true,
  })
  if (!ok) return
  try {
    if (published) {
      await agentsApi.update(id, { status: 'archived' })
    }
    await store.remove(id)
    success(t('assistants.deleteSuccess'))
  } catch {
    error(t('assistants.deleteFailed'))
  }
}

async function cloneAssistant(id: number): Promise<void> {
  try {
    const clone = await store.clone(id)
    success(t('assistants.cloneSuccess'))
    if (clone.id) {
      await router.push({ name: 'ai-assistant-builder', params: { id: String(clone.id) } })
    }
  } catch {
    error(t('assistants.cloneFailed'))
  }
}

onMounted(() => {
  if (builderMode.value) {
    void openBuilder(String(route.params.id ?? ''))
  } else {
    void store.loadGallery().catch(() => error(t('assistants.loadFailed')))
  }
})

watch(
  () => [route.name, route.params.id],
  () => {
    if (builderMode.value) {
      void openBuilder(String(route.params.id ?? ''))
    } else {
      void store.loadGallery().catch(() => error(t('assistants.loadFailed')))
    }
  }
)

onUnmounted(() => {
  store.clear()
})
</script>
