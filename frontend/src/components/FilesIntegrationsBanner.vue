<template>
  <!--
    Small, dismissible info card promoting the Nextcloud + OpenCloud
    integrations to users on the Files / RAG manager page. Lives next to the
    storage-quota widget so it shares the same visual rhythm. The dismissal
    is local-only (no backend persistence): the user can hide it for this
    browser/profile, and a logged-out / fresh device will see it again — that
    is on purpose for a marketing surface.

    Lives at the page level (not in MainLayout) so it never leaks into the
    chat / settings / admin screens, where it would just be noise.
  -->
  <div
    v-if="!dismissed"
    class="surface-card px-4 py-3 sm:px-5 sm:py-4 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4"
    data-testid="banner-cloud-integrations"
  >
    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium txt-primary">
        {{ $t('files.integrationsBanner.title') }}
      </p>
      <p class="text-xs txt-secondary mt-0.5">
        {{ $t('files.integrationsBanner.subtitle') }}
      </p>
    </div>

    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
      <a
        v-for="integration in integrations"
        :key="integration.name"
        :href="integration.url"
        target="_blank"
        rel="noopener noreferrer"
        class="group flex items-center gap-2 px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/15 hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/[0.03] transition-all"
        :title="$t('files.integrationsBanner.openLink', { name: integration.name })"
        :data-testid="`btn-integration-${integration.id}`"
      >
        <img
          :src="integration.iconUrl"
          :alt="integration.name"
          class="w-6 h-6 object-contain flex-shrink-0"
          width="24"
          height="24"
          loading="lazy"
        />
        <span class="text-xs font-medium txt-primary group-hover:text-[var(--brand)]">
          {{ integration.name }}
        </span>
      </a>

      <button
        type="button"
        class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors flex-shrink-0"
        :aria-label="$t('files.integrationsBanner.dismiss')"
        :title="$t('files.integrationsBanner.dismiss')"
        data-testid="btn-integrations-dismiss"
        @click="dismiss"
      >
        <Icon icon="heroicons:x-mark" class="w-4 h-4" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'

// Bumping this storage key forces the banner to reappear for users who'd
// previously dismissed it — useful when we add a third integration and want
// to surface it to existing users. Keep the suffix versioned.
const DISMISS_STORAGE_KEY = 'synaplan.files.cloudIntegrationsBanner.dismissed.v1'

const dismissed = ref(
  typeof window !== 'undefined' && window.localStorage.getItem(DISMISS_STORAGE_KEY) === '1'
)

// Repo URLs are the actionable target: customers landing in self-hosted file
// territory are already comfortable with GitHub READMEs, so pointing at the
// canonical install / config docs (not a marketing page) shortens the path
// from "interest" to "installation". Order is intentional: Nextcloud first
// (much larger user base), OpenCloud second.
const integrations = [
  {
    id: 'nextcloud',
    name: 'Nextcloud',
    url: 'https://github.com/metadist/synaplan-nextcloud',
    // Files in /public are served from the root path in dev + prod — no need
    // to go through Vite's asset pipeline (would force a hash and break
    // direct file inspection from the Files page).
    iconUrl: '/integrations/nextcloud.svg',
  },
  {
    id: 'opencloud',
    name: 'OpenCloud',
    url: 'https://github.com/metadist/synaplan-opencloud',
    iconUrl: '/integrations/opencloud.svg',
  },
]

function dismiss() {
  dismissed.value = true
  try {
    window.localStorage.setItem(DISMISS_STORAGE_KEY, '1')
  } catch {
    // localStorage can be unavailable (private mode, quota); the in-memory
    // ref still hides the banner for the rest of this session, which is the
    // expected UX when storage fails.
  }
}
</script>
