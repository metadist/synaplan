<template>
  <div v-if="visible" class="relative min-w-0 flex-shrink">
    <button
      ref="trigger"
      type="button"
      class="inline-flex h-8 max-w-[10rem] items-center gap-1.5 rounded-lg border border-light-border/30 px-2 text-xs font-medium txt-secondary hover:txt-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] dark:border-dark-border/20"
      :class="{ 'txt-primary': current }"
      :aria-label="$t('projects.picker.label', { name: label })"
      :title="$t('projects.picker.label', { name: label })"
      :aria-expanded="open"
      aria-haspopup="menu"
      :disabled="busy"
      data-testid="btn-chat-project"
      @click="toggle"
    >
      <FolderIcon class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
      <span class="truncate" :class="{ 'hidden sm:inline': !current }">{{ label }}</span>
      <ChevronDownIcon class="h-3 w-3 flex-shrink-0" aria-hidden="true" />
    </button>

    <Teleport :to="teleportTarget">
      <div v-if="open" class="fixed inset-0 z-[150]" @click="close">
        <div
          ref="panel"
          role="menu"
          class="dropdown-panel scroll-thin fixed w-max min-w-48 max-w-[calc(100vw-16px)] overflow-y-auto whitespace-nowrap"
          :style="style"
          data-testid="menu-chat-project"
          @click.stop
          @keydown="onPanelKeydown"
        >
          <button
            type="button"
            role="menuitemradio"
            :aria-checked="!current"
            class="dropdown-item"
            data-testid="btn-chat-project-none"
            @click="choose(null)"
          >
            <CheckIcon class="h-4 w-4 flex-shrink-0" :class="{ invisible: current }" />
            {{ $t('projects.picker.none') }}
          </button>
          <button
            v-for="project in store.projects"
            :key="project.id"
            type="button"
            role="menuitemradio"
            :aria-checked="project.id === current?.id"
            class="dropdown-item"
            :data-testid="`btn-chat-project-${project.id}`"
            @click="choose(project.id)"
          >
            <CheckIcon
              class="h-4 w-4 flex-shrink-0"
              :class="{ invisible: project.id !== current?.id }"
            />
            <span class="truncate">{{ project.name }}</span>
          </button>
          <p v-if="store.projects.length === 0" class="px-3 py-2 text-xs txt-secondary">
            {{ $t('projects.picker.empty') }}
          </p>
          <router-link
            v-if="current"
            :to="`/projects/${current.id}`"
            role="menuitem"
            class="dropdown-item mt-1 border-t border-light-border/30 dark:border-dark-border/20"
            data-testid="link-chat-project-open"
            @click="close"
          >
            <ArrowTopRightOnSquareIcon class="h-4 w-4 flex-shrink-0" />
            {{ $t('projects.picker.open') }}
          </router-link>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowTopRightOnSquareIcon,
  CheckIcon,
  ChevronDownIcon,
  FolderIcon,
} from '@heroicons/vue/24/outline'
import { useProjectsStore } from '@/stores/projects'
import { useProjectChatsStore } from '@/stores/projectChats'
import { useChatsStore } from '@/stores/chats'
import { useNotification } from '@/composables/useNotification'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'

const EDGE_GAP = 8
const GAP = 6

/** The composer is only offered where the chat is the signed-in person's own. */
const props = defineProps<{ enabled: boolean }>()

const { t } = useI18n()
const store = useProjectsStore()
const projectChats = useProjectChatsStore()
const chats = useChatsStore()
const { error: showError } = useNotification()
const { teleportTarget } = useFullscreenTeleportTarget()

const open = ref(false)
const busy = ref(false)
const trigger = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const style = ref<Record<string, string>>({})

const visible = computed(() => props.enabled && store.available && store.loaded)
const current = computed(() => store.projectOfChat(chats.activeChatId))
const label = computed(() => current.value?.name ?? t('projects.picker.none'))

function place(): void {
  const rect = trigger.value?.getBoundingClientRect()
  if (!rect) return
  const width = panel.value?.offsetWidth ?? 0
  const left = Math.max(EDGE_GAP, Math.min(rect.left, window.innerWidth - width - EDGE_GAP))
  // The composer sits at the bottom of the page, so the list opens upwards.
  style.value = {
    left: `${left}px`,
    bottom: `${window.innerHeight - rect.top + GAP}px`,
    maxHeight: `${Math.max(160, rect.top - GAP - EDGE_GAP)}px`,
  }
}

async function toggle(): Promise<void> {
  if (open.value) return close()
  place()
  open.value = true
  await nextTick()
  place()
  const items = panel.value?.querySelectorAll<HTMLElement>('[role^="menuitem"]')
  const checked = panel.value?.querySelector<HTMLElement>('[aria-checked="true"]')
  ;(checked ?? items?.[0])?.focus()
}

function close(): void {
  if (!open.value) return
  open.value = false
  void nextTick(() => trigger.value?.focus())
}

function onPanelKeydown(event: KeyboardEvent): void {
  const items = [...(panel.value?.querySelectorAll<HTMLElement>('[role^="menuitem"]') ?? [])]
  const at = items.indexOf(document.activeElement as HTMLElement)
  if (event.key === 'ArrowDown') items[(at + 1) % items.length]?.focus()
  else if (event.key === 'ArrowUp') items[(at - 1 + items.length) % items.length]?.focus()
  else if (event.key === 'Home') items[0]?.focus()
  else if (event.key === 'End') items[items.length - 1]?.focus()
  else return
  event.preventDefault()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') close()
}

/** A blank page has no chat yet; picking a project there creates it, as sending would. */
async function choose(projectId: string | null): Promise<void> {
  if (busy.value || projectId === (current.value?.id ?? null)) return close()
  busy.value = true
  open.value = false
  try {
    const chatId = chats.activeChatId ?? (await chats.createChat())?.id ?? null
    if (chatId === null) throw new Error('no chat')
    if (projectId === null) {
      if (current.value) await projectChats.remove(current.value.id, chatId)
    } else {
      await projectChats.move(projectId, chatId)
    }
  } catch {
    showError(t('projects.move.failed'))
  } finally {
    busy.value = false
    void nextTick(() => trigger.value?.focus())
  }
}

onMounted(() => void store.loadOnce())
document.addEventListener('keydown', onKeydown)
window.addEventListener('resize', close)
onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('resize', close)
})
</script>
