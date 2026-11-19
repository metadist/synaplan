<template>
  <div v-if="store.available" class="mt-5" data-testid="section-sidebar-projects">
    <div class="group/section relative">
      <button
        type="button"
        :class="toggleClass"
        :aria-expanded="expanded"
        aria-controls="sidebar-projects-list"
        data-testid="btn-sidebar-projects-toggle"
        @click="expanded = !expanded"
      >
        <FolderIcon class="h-4 w-4 flex-shrink-0 txt-secondary" aria-hidden="true" />
        {{ $t('projects.title') }}
        <ChevronDownIcon :class="chevronClass" aria-hidden="true" />
      </button>
      <button
        type="button"
        class="absolute right-3 top-1/2 z-10 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md txt-secondary hover:text-[var(--txt-primary)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] pointer-events-none opacity-0 transition-opacity focus-visible:pointer-events-auto focus-visible:opacity-100 group-hover/section:pointer-events-auto group-hover/section:opacity-100"
        :title="$t('projects.new')"
        :aria-label="$t('projects.new')"
        data-testid="btn-sidebar-project-new"
        @click="createProject"
      >
        <PlusIcon class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
    <div v-show="expanded" id="sidebar-projects-list" class="px-2">
      <p
        v-if="store.loaded && store.projects.length === 0"
        class="sidebar-empty-hint px-3 py-2 text-sm"
        data-testid="text-sidebar-projects-empty"
      >
        {{ $t('projects.sidebarEmpty') }}
      </p>
      <ul v-else class="space-y-0.5" data-testid="list-sidebar-projects">
        <li
          v-for="project in store.projects"
          :key="project.id"
          class="group/project relative overflow-hidden rounded-lg"
        >
          <router-link
            :to="`/projects/${project.id}`"
            class="project-row-link flex items-center rounded-lg text-[15px] leading-5 truncate hover:bg-black/[0.04] dark:hover:bg-white/[0.04]"
            active-class="project-row-link-active"
            :data-testid="`link-sidebar-project-${project.id}`"
          >
            <Icon
              v-if="project.pinned"
              icon="mdi:pin"
              class="mr-1.5 h-3.5 w-3.5 flex-shrink-0 txt-secondary"
              :aria-label="$t('projects.pinned')"
              role="img"
            />
            <span class="truncate">{{ project.name }}</span>
          </router-link>
          <ProjectRowMenu :project="project" />
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ChevronDownIcon, FolderIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import ProjectRowMenu from '@/components/projects/ProjectRowMenu.vue'
import { useProjectsStore } from '@/stores/projects'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const EXPANDED_KEY = 'sidebar-projects-expanded'
const NAME_MAX = 120

const { t } = useI18n()
const router = useRouter()
const store = useProjectsStore()
const { prompt } = useDialog()
const { error: showError } = useNotification()

function readExpanded(): boolean {
  try {
    return localStorage.getItem(EXPANDED_KEY) !== 'false'
  } catch {
    return true
  }
}

const expanded = ref(readExpanded())
watch(expanded, (open) => {
  try {
    localStorage.setItem(EXPANDED_KEY, String(open))
  } catch {
    // Storage can be blocked; the section still toggles for this visit.
  }
})

const toggleClass =
  'sidebar-section-label mb-0.5 flex w-full items-center gap-1.5 bg-transparent px-4 py-1 text-left text-[15px] font-semibold hover:bg-transparent focus:bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--brand)]'

const chevronClass = computed(() =>
  expanded.value
    ? 'h-3.5 w-3.5 flex-shrink-0 opacity-0 group-hover/section:opacity-100 transition-opacity'
    : 'h-3.5 w-3.5 flex-shrink-0 -rotate-90 opacity-100'
)

async function createProject(): Promise<void> {
  const name = await prompt({
    title: t('projects.new'),
    message: t('projects.newMessage'),
    placeholder: t('projects.namePlaceholder'),
    confirmText: t('projects.create'),
    cancelText: t('common.cancel'),
  })
  const trimmed = name?.trim().slice(0, NAME_MAX)
  if (!trimmed) return
  try {
    const project = await store.create(trimmed)
    await router.push(`/projects/${project.id}`)
  } catch {
    showError(t('projects.createFailed'))
  }
}

onMounted(() => void store.loadOnce())
</script>

<style scoped>
/* Same inks as the Chats panel (its scoped classes do not reach this
   component): section label, empty hint and history-weight rows. */
.sidebar-section-label {
  color: #2d3545;
}

.sidebar-empty-hint {
  color: #293141;
}

.project-row-link {
  padding: 0.3125rem 0.375rem;
  font-weight: 400;
  color: #3b4353;
}

.project-row-link-active {
  font-weight: 500;
  color: var(--brand);
  background-color: color-mix(in srgb, var(--brand) 12%, transparent);
}

.dark .sidebar-section-label {
  color: #b0b7c4;
}

.dark .sidebar-empty-hint {
  color: #a7afbe;
}

.dark .project-row-link {
  color: #cdd1da;
}

.dark .project-row-link-active {
  color: var(--brand);
}
</style>
