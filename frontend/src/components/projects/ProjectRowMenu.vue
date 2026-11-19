<template>
  <div class="project-row-menu absolute inset-y-0 right-0 flex items-stretch">
    <button
      type="button"
      class="project-row-menu-btn icon-ghost inline-flex h-full items-center justify-center px-1.5"
      :aria-label="project.pinned ? $t('projects.unpin') : $t('projects.pin')"
      :aria-pressed="project.pinned"
      data-testid="btn-sidebar-project-pin"
      @click="actions.togglePin(project)"
    >
      <Icon
        :icon="project.pinned ? 'ph:push-pin-fill' : 'ph:push-pin'"
        class="h-4 w-4"
        aria-hidden="true"
      />
    </button>
    <button
      type="button"
      class="project-row-menu-btn icon-ghost inline-flex h-full items-center justify-center px-1.5"
      :aria-label="$t('projects.menu.label', { name: project.name })"
      :aria-expanded="open"
      aria-haspopup="menu"
      data-testid="btn-sidebar-project-menu"
      @click="toggle"
    >
      <EllipsisHorizontalIcon class="h-5 w-5" aria-hidden="true" />
    </button>
  </div>

  <Teleport to="#app">
    <div v-if="open" class="fixed inset-0 z-[150]" @click="open = false">
      <div
        ref="panel"
        role="menu"
        class="fixed w-52 dropdown-panel origin-top-right"
        :style="style"
        @click.stop
      >
        <button
          type="button"
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-sidebar-project-new-chat"
          @click="run(actions.newProjectChat)"
        >
          <ChatBubbleLeftIcon class="h-4 w-4" aria-hidden="true" />
          {{ $t('projects.chats.new') }}
        </button>
        <button
          type="button"
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-sidebar-project-rename"
          @click="run(actions.renameProject)"
        >
          <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
          {{ $t('common.rename') }}
        </button>
        <button
          type="button"
          role="menuitem"
          class="dropdown-item dropdown-item--danger"
          data-testid="btn-sidebar-project-delete"
          @click="run(actions.deleteProject)"
        >
          <TrashIcon class="h-4 w-4" aria-hidden="true" />
          {{ $t('common.delete') }}
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { nextTick, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import {
  ChatBubbleLeftIcon,
  EllipsisHorizontalIcon,
  PencilSquareIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import type { ProjectItem } from '@/services/api/projectsApi'
import { useProjectActions } from '@/composables/useProjectActions'

const MENU_WIDTH = 208
const EDGE_GAP = 8

const props = defineProps<{ project: ProjectItem }>()

const actions = useProjectActions()
const open = ref(false)
const panel = ref<HTMLElement | null>(null)
const style = ref<Record<string, string>>({})

function toggle(event: MouseEvent): void {
  if (open.value) {
    open.value = false
    return
  }
  const anchor = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const left = Math.max(
    EDGE_GAP,
    Math.min(anchor.right - MENU_WIDTH, window.innerWidth - MENU_WIDTH - EDGE_GAP)
  )
  style.value = { top: `${anchor.bottom + 4}px`, left: `${left}px` }
  open.value = true
  void nextTick(() => place(anchor))
}

/** Flip above the button when the menu would run off the bottom of the window. */
function place(anchor: DOMRect): void {
  const height = panel.value?.offsetHeight ?? 0
  const maxTop = window.innerHeight - height - EDGE_GAP
  const below = anchor.bottom + 4
  const top =
    below <= maxTop ? below : Math.max(EDGE_GAP, Math.min(anchor.top - height - 4, maxTop))
  style.value = { ...style.value, top: `${top}px` }
}

function run(action: (project: ProjectItem) => Promise<void>): void {
  open.value = false
  void action(props.project)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') open.value = false
}

function close(): void {
  open.value = false
}

document.addEventListener('keydown', onKeydown)
window.addEventListener('scroll', close, true)
window.addEventListener('resize', close)
onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<style scoped>
.project-row-menu {
  /* Same plate as the chat rows: the title fades out under the buttons. */
  --project-row-plate: #ccd6e5;
  padding-left: 0.5rem;
  background: linear-gradient(to right, transparent, var(--project-row-plate) 0.5rem);
  opacity: 1;
  pointer-events: none;
}

.dark .project-row-menu {
  --project-row-plate: #0c101a;
}

.project-row-menu-btn.icon-ghost {
  pointer-events: auto;
  background: transparent;
  box-shadow: none;
  border-radius: 0;
  color: var(--txt-secondary);
  opacity: 0.72;
}

.project-row-menu-btn.icon-ghost:hover,
.project-row-menu-btn.icon-ghost:focus-visible {
  color: var(--txt-primary);
  opacity: 1;
}

.project-row-menu-btn.icon-ghost:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: -2px;
}

@media (hover: hover) and (pointer: fine) {
  .project-row-menu {
    opacity: 0;
  }

  .project-row-menu-btn.icon-ghost {
    pointer-events: none;
  }

  .group\/project:hover .project-row-menu-btn.icon-ghost,
  .group\/project:focus-within .project-row-menu-btn.icon-ghost {
    pointer-events: auto;
  }

  .group\/project:hover .project-row-menu,
  .group\/project:focus-within .project-row-menu {
    opacity: 1;
  }
}
</style>
