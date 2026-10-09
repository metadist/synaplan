<template>
  <MainLayout data-testid="view-assistants">
    <div class="min-h-screen overflow-x-hidden bg-chat px-3 py-4 sm:p-4 md:p-8">
      <div class="mx-auto w-full max-w-[100rem]">
        <PageHeader
          :title="headerTitle"
          :subtitle="builderMode ? currentName : $t('assistants.intro')"
          icon="mdi:robot-outline"
          tour-id="assistants"
        >
          <button
            v-if="!builderMode"
            type="button"
            class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium inline-flex items-center gap-2"
            data-testid="btn-create-assistant-header"
            data-tour="assistants-create"
            @click="createAssistant"
          >
            {{ $t('assistants.create') }}
          </button>
          <button
            v-else
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
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
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
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
import { agentsApi, type Agent } from '@/services/api/agentsApi'

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

const untouchedDraft = ref<{ id: number; snapshot: string } | null>(null)

function editableSnapshot(agent: Agent): string {
  return JSON.stringify({
    name: agent.name ?? '',
    description: agent.description ?? '',
    icon: agent.icon ?? '',
    routable: agent.routable ?? false,
    draft: agent.draft ?? null,
  })
}

async function discardUntouchedDraft(): Promise<void> {
  const mark = untouchedDraft.value
  const current = store.current
  if (!mark || mark.snapshot === '' || !current || current.id !== mark.id) return
  if (store.hasUnsavedWork || current.status !== 'draft') return
  if (editableSnapshot(current) !== mark.snapshot) return
  untouchedDraft.value = null
  try {
    await store.remove(mark.id)
  } catch {
    // Leave the row in place if the delete fails; the person can remove it from the list.
  }
}

async function openBuilder(id: string): Promise<void> {
  if (id === 'new') {
    try {
      const agent = await store.create(t('assistants.untitled'))
      if (agent.id == null) {
        error(t('assistants.createFailed'))
        return
      }
      untouchedDraft.value = { id: agent.id, snapshot: '' }
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
  if (untouchedDraft.value?.id === numericId && store.current?.id === numericId) {
    if (untouchedDraft.value.snapshot === '') {
      untouchedDraft.value = { id: numericId, snapshot: editableSnapshot(store.current) }
    }
    return
  }
  try {
    const agent = await store.load(numericId)
    if (untouchedDraft.value?.id === numericId && untouchedDraft.value.snapshot === '') {
      untouchedDraft.value = { id: numericId, snapshot: editableSnapshot(agent) }
    }
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
  store.clear()
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

function setupBuilderLeaveGuard(): () => void {
  const hasPendingWork = () => store.hasUnsavedWork
  const handleBeforeUnload = (event: BeforeUnloadEvent) => {
    if (!hasPendingWork()) {
      return
    }
    event.preventDefault()
    event.returnValue = ''
  }
  window.addEventListener('beforeunload', handleBeforeUnload)

  const removeGuard = router.beforeEach(async (to, from, next) => {
    const leavingBuilder =
      from.name === 'ai-assistant-builder' && to.name !== 'ai-assistant-builder'
    const switchingAssistant =
      from.name === 'ai-assistant-builder' &&
      to.name === 'ai-assistant-builder' &&
      String(to.params.id) !== String(from.params.id)
    if (!hasPendingWork() || (!leavingBuilder && !switchingAssistant)) {
      if (
        (leavingBuilder || switchingAssistant) &&
        String(to.params.id) !== String(untouchedDraft.value?.id ?? '')
      ) {
        await discardUntouchedDraft()
      }
      if (leavingBuilder) {
        store.clear()
      }
      next()
      return
    }
    const ok = await confirm({
      title: t('unsavedChanges.title'),
      message: t('unsavedChanges.confirmLeave'),
      confirmText: t('common.leave'),
      cancelText: t('common.stay'),
      danger: true,
    })
    if (ok && leavingBuilder) {
      await discardUntouchedDraft()
      store.clear()
    }
    next(ok)
  })

  return () => {
    window.removeEventListener('beforeunload', handleBeforeUnload)
    removeGuard()
  }
}

let stopLeaveGuard: (() => void) | null = null

onMounted(() => {
  stopLeaveGuard = setupBuilderLeaveGuard()
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
  stopLeaveGuard?.()
  store.clear()
})
</script>
