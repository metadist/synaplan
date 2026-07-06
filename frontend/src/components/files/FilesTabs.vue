<template>
  <div data-testid="tabs-files">
    <!-- Desktop / tablet: horizontal tabs. Fits without scrolling on md+, so no
         overflow container is needed (that was the source of the stray x/y
         scrollbars on phones). -->
    <nav
      class="hidden md:flex items-center gap-1 border-b border-light-border/20 dark:border-dark-border/10"
      :aria-label="$t('nav.files')"
    >
      <router-link
        v-for="tab in tabs"
        :key="tab.name"
        :to="tab.path"
        :class="tabClass(tab.name)"
        :aria-current="active === tab.name ? 'page' : undefined"
        :data-testid="tab.testid"
      >
        <component :is="tab.icon" class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t(tab.labelKey) }}</span>
        <span
          v-if="tab.name === 'incoming' && incomingCount > 0"
          class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          data-testid="tab-incoming-badge"
        >
          {{ incomingCount }}
        </span>
      </router-link>
    </nav>

    <!-- Mobile: a single dropdown replaces the scrolling tab row. The trigger
         shows the current section; the panel lists every destination. -->
    <div ref="dropdownRef" class="md:hidden relative">
      <button
        type="button"
        class="dropdown-trigger surface-card w-full justify-between border border-light-border/20 dark:border-dark-border/10"
        :aria-expanded="menuOpen"
        aria-haspopup="menu"
        data-testid="tab-files-mobile-trigger"
        @click="toggleMenu"
      >
        <span class="flex items-center gap-2 txt-primary font-medium">
          <component :is="activeTab.icon" class="w-4 h-4" aria-hidden="true" />
          <span>{{ $t(activeTab.labelKey) }}</span>
          <span
            v-if="activeTab.name === 'incoming' && incomingCount > 0"
            class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          >
            {{ incomingCount }}
          </span>
        </span>
        <ChevronDownIcon
          class="w-4 h-4 transition-transform"
          :class="{ 'rotate-180': menuOpen }"
          aria-hidden="true"
        />
      </button>

      <div
        v-if="menuOpen"
        class="dropdown-panel absolute left-0 right-0 top-full mt-1 z-30 flex flex-col gap-1"
        role="menu"
        data-testid="tab-files-mobile-menu"
      >
        <button
          v-for="tab in tabs"
          :key="tab.name"
          type="button"
          role="menuitem"
          :class="['dropdown-item', active === tab.name && 'dropdown-item--active']"
          :data-testid="`${tab.testid}-mobile`"
          @click="selectTab(tab.path)"
        >
          <component :is="tab.icon" class="w-5 h-5 flex-shrink-0" aria-hidden="true" />
          <span class="flex-1">{{ $t(tab.labelKey) }}</span>
          <span
            v-if="tab.name === 'incoming' && incomingCount > 0"
            class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          >
            {{ incomingCount }}
          </span>
        </button>
      </div>
    </div>

    <!-- §4.8 #1: one vocabulary — say what the chat input already implies. -->
    <p class="text-sm txt-secondary mt-3">{{ $t('files.intro') }}</p>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, type Component } from 'vue'
import { useRouter } from 'vue-router'
import {
  ChevronDownIcon,
  CircleStackIcon,
  FolderIcon,
  InboxArrowDownIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import filesService from '@/services/filesService'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

type FilesTab = 'files' | 'search' | 'vectors' | 'incoming' | 'generated'

interface TabDef {
  name: FilesTab
  path: string
  labelKey: string
  icon: Component
  testid: string
}

const props = defineProps<{
  active: FilesTab
}>()

const router = useRouter()

const tabs: TabDef[] = [
  { name: 'files', path: '/files', labelKey: 'files.tabBrowse', icon: FolderIcon, testid: 'tab-files-browse' },
  {
    name: 'incoming',
    path: '/files/incoming',
    labelKey: 'files.tabIncoming',
    icon: InboxArrowDownIcon,
    testid: 'tab-files-incoming',
  },
  {
    name: 'generated',
    path: '/files/generated',
    labelKey: 'files.tabGenerated',
    icon: SparklesIcon,
    testid: 'tab-files-generated',
  },
  { name: 'search', path: '/files/search', labelKey: 'files.tabSearch', icon: MagnifyingGlassIcon, testid: 'tab-files-search' },
  { name: 'vectors', path: '/files/vectors', labelKey: 'files.tabVectors', icon: CircleStackIcon, testid: 'tab-files-vectors' },
]

const activeTab = computed(() => tabs.find((tab) => tab.name === props.active) ?? tabs[0])

// Tailwind-only tab styling (AGENTS.md: no component-scoped CSS). The `-mb-px`
// pulls the active 2px bottom border over the nav's own border for the
// classic tab look; brand color comes from the CSS token.
const BASE_TAB =
  'inline-flex items-center gap-1.5 px-4 py-2 -mb-px text-sm font-medium border-b-2 whitespace-nowrap transition-colors'

const tabClass = (name: FilesTab): string =>
  props.active === name
    ? `${BASE_TAB} text-[var(--brand)] border-[var(--brand)]`
    : `${BASE_TAB} txt-secondary border-transparent hover:text-[var(--brand)]`

const menuOpen = ref(false)
const dropdownRef = ref<HTMLElement | null>(null)

const toggleMenu = () => {
  triggerHapticImpact('light')
  menuOpen.value = !menuOpen.value
}

const closeMenu = () => {
  if (!menuOpen.value) return
  triggerHapticImpact('light')
  menuOpen.value = false
}

const selectTab = (path: string) => {
  closeMenu()
  router.push(path)
}

const handleOutsideClick = (event: MouseEvent) => {
  if (!menuOpen.value) return
  if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
    menuOpen.value = false
  }
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') menuOpen.value = false
}

// Incoming count badge — shown on every files tab so the user notices when
// integrations have pushed new files for triage (§4.5). Best-effort, silent.
const incomingCount = ref(0)

onMounted(async () => {
  document.addEventListener('click', handleOutsideClick)
  document.addEventListener('keydown', handleEscape)
  try {
    const facets = await filesService.getFacets()
    incomingCount.value = facets.incoming
  } catch {
    incomingCount.value = 0
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
  document.removeEventListener('keydown', handleEscape)
})
</script>
