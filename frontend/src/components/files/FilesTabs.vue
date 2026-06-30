<template>
  <div data-testid="tabs-files">
    <nav
      class="flex items-center gap-1 border-b border-light-border/20 dark:border-dark-border/10 overflow-x-auto scroll-thin"
      :aria-label="$t('nav.files')"
    >
      <router-link
        to="/files"
        :class="tabClass('files')"
        :aria-current="active === 'files' ? 'page' : undefined"
        data-testid="tab-files-browse"
      >
        <FolderIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabBrowse') }}</span>
      </router-link>
      <router-link
        to="/files/incoming"
        :class="tabClass('incoming')"
        :aria-current="active === 'incoming' ? 'page' : undefined"
        data-testid="tab-files-incoming"
      >
        <InboxArrowDownIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabIncoming') }}</span>
        <span
          v-if="incomingCount > 0"
          class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          data-testid="tab-incoming-badge"
        >
          {{ incomingCount }}
        </span>
      </router-link>
      <router-link
        to="/files/generated"
        :class="tabClass('generated')"
        :aria-current="active === 'generated' ? 'page' : undefined"
        data-testid="tab-files-generated"
      >
        <SparklesIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabGenerated') }}</span>
      </router-link>
      <router-link
        to="/files/search"
        :class="tabClass('search')"
        :aria-current="active === 'search' ? 'page' : undefined"
        data-testid="tab-files-search"
      >
        <MagnifyingGlassIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabSearch') }}</span>
      </router-link>
      <router-link
        to="/files/vectors"
        :class="tabClass('vectors')"
        :aria-current="active === 'vectors' ? 'page' : undefined"
        data-testid="tab-files-vectors"
      >
        <CircleStackIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabVectors') }}</span>
      </router-link>
    </nav>
    <!-- §4.8 #1: one vocabulary — say what the chat input already implies. -->
    <p class="text-sm txt-secondary mt-3">{{ $t('files.intro') }}</p>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import {
  CircleStackIcon,
  FolderIcon,
  InboxArrowDownIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import filesService from '@/services/filesService'

type FilesTab = 'files' | 'search' | 'vectors' | 'incoming' | 'generated'

const props = defineProps<{
  active: FilesTab
}>()

// Tailwind-only tab styling (AGENTS_DEV: no component-scoped CSS). The `-mb-px`
// pulls the active 2px bottom border over the nav's own border for the
// classic tab look; brand color comes from the CSS token.
const BASE_TAB =
  'inline-flex items-center gap-1.5 px-4 py-2 -mb-px text-sm font-medium border-b-2 whitespace-nowrap transition-colors'

const tabClass = (name: FilesTab): string =>
  props.active === name
    ? `${BASE_TAB} text-[var(--brand)] border-[var(--brand)]`
    : `${BASE_TAB} txt-secondary border-transparent hover:text-[var(--brand)]`

// Incoming count badge — shown on every files tab so the user notices when
// integrations have pushed new files for triage (§4.5). Best-effort, silent.
const incomingCount = ref(0)

onMounted(async () => {
  try {
    const facets = await filesService.getFacets()
    incomingCount.value = facets.incoming
  } catch {
    incomingCount.value = 0
  }
})
</script>
