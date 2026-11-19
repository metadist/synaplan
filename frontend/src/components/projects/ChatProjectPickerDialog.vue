<template>
  <Teleport :to="teleportTarget">
    <div
      v-if="open"
      class="modal-overlay fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
      data-testid="modal-chat-project-picker"
      @click.self="emit('close')"
    >
      <div
        ref="panel"
        class="modal-panel surface-card rounded-2xl shadow-2xl max-w-md w-full p-4 sm:p-6 space-y-4 focus:outline-none"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
      >
        <h3 :id="titleId" class="text-base sm:text-lg font-semibold txt-primary">
          {{ $t('projects.move.title') }}
        </h3>
        <p v-if="store.projects.length === 0" class="text-sm txt-secondary">
          {{ $t('projects.move.none') }}
        </p>
        <ul v-else class="space-y-1 max-h-72 overflow-y-auto scroll-thin">
          <li v-for="project in store.projects" :key="project.id">
            <button
              type="button"
              class="w-full rounded-xl px-3 py-2.5 text-left text-sm txt-primary hover:bg-black/5 dark:hover:bg-white/5 disabled:opacity-50"
              :disabled="busy || project.id === currentProjectId"
              :aria-current="project.id === currentProjectId ? 'true' : undefined"
              :data-testid="`btn-chat-move-to-${project.id}`"
              @click="move(project.id)"
            >
              <span class="block truncate font-medium">{{ project.name }}</span>
              <span v-if="project.id === currentProjectId" class="text-xs txt-secondary">
                {{ $t('projects.move.current') }}
              </span>
            </button>
          </li>
        </ul>
        <div class="flex justify-between gap-2 pt-2">
          <button
            v-if="currentProjectId"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :disabled="busy"
            data-testid="btn-chat-move-out"
            @click="moveOut"
          >
            {{ $t('projects.move.out') }}
          </button>
          <span v-else></span>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-chat-move-cancel"
            @click="emit('close')"
          >
            {{ $t('common.cancel') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, toRef, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { useEscapeKey } from '@/composables/useEscapeKey'
import { useNotification } from '@/composables/useNotification'
import { useProjectsStore } from '@/stores/projects'
import { useProjectChatsStore } from '@/stores/projectChats'

const props = defineProps<{ open: boolean; chatId: number | null }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { teleportTarget } = useFullscreenTeleportTarget()
const { success, error: showError } = useNotification()
const store = useProjectsStore()
const chatsStore = useProjectChatsStore()

const titleId = `chat-project-picker-${useId()}`
const busy = ref(false)
const panel = ref<HTMLElement | null>(null)

const currentProjectId = computed(() => store.projectOfChat(props.chatId)?.id ?? null)

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen) return
    void store.loadOnce()
    await nextTick()
    panel.value?.focus()
  }
)

useEscapeKey(
  () => {
    if (!busy.value) emit('close')
  },
  toRef(props, 'open')
)

async function move(projectId: string): Promise<void> {
  if (props.chatId === null) return
  busy.value = true
  try {
    await chatsStore.move(projectId, props.chatId)
    success(t('projects.move.moved'))
    emit('close')
  } catch {
    showError(t('projects.move.failed'))
  } finally {
    busy.value = false
  }
}

async function moveOut(): Promise<void> {
  const projectId = currentProjectId.value
  if (props.chatId === null || !projectId) return
  busy.value = true
  try {
    await chatsStore.remove(projectId, props.chatId)
    success(t('projects.move.removed'))
    emit('close')
  } catch {
    showError(t('projects.move.failed'))
  } finally {
    busy.value = false
  }
}
</script>
