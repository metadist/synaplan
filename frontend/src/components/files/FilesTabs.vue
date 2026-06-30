<template>
  <div data-testid="tabs-files">
    <nav
      class="flex items-center gap-1 border-b border-light-border/20 dark:border-dark-border/10 overflow-x-auto scroll-thin"
      :aria-label="$t('nav.files')"
    >
      <router-link
        to="/files"
        class="files-tab txt-secondary"
        :class="active === 'files' && 'files-tab--active'"
        :aria-current="active === 'files' ? 'page' : undefined"
        data-testid="tab-files-browse"
      >
        <FolderIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabBrowse') }}</span>
      </router-link>
      <router-link
        to="/files/incoming"
        class="files-tab txt-secondary"
        :class="active === 'incoming' && 'files-tab--active'"
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
        class="files-tab txt-secondary"
        :class="active === 'generated' && 'files-tab--active'"
        :aria-current="active === 'generated' ? 'page' : undefined"
        data-testid="tab-files-generated"
      >
        <SparklesIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabGenerated') }}</span>
      </router-link>
      <router-link
        to="/files/search"
        class="files-tab txt-secondary"
        :class="active === 'search' && 'files-tab--active'"
        :aria-current="active === 'search' ? 'page' : undefined"
        data-testid="tab-files-search"
      >
        <MagnifyingGlassIcon class="w-4 h-4" aria-hidden="true" />
        <span>{{ $t('files.tabSearch') }}</span>
      </router-link>
      <router-link
        to="/files/vectors"
        class="files-tab txt-secondary"
        :class="active === 'vectors' && 'files-tab--active'"
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

defineProps<{
  active: 'files' | 'search' | 'vectors' | 'incoming' | 'generated'
}>()

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

<style scoped>
.files-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 1rem;
  margin-bottom: -1px;
  font-size: 0.875rem;
  font-weight: 500;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition:
    color 0.15s ease,
    border-color 0.15s ease;
}

.files-tab:hover,
.files-tab--active {
  color: var(--brand) !important;
}

.files-tab--active {
  border-bottom-color: var(--brand);
}
</style>
