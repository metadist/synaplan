<template>
  <div data-testid="tabs-files">
    <nav
      class="flex items-center gap-1 border-b border-light-border/20 dark:border-dark-border/10"
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
        <span>{{ $t('files.tabFiles') }}</span>
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
import { CircleStackIcon, FolderIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

defineProps<{
  active: 'files' | 'search' | 'vectors'
}>()
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
