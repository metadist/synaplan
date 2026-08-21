<template>
  <div
    v-if="visible"
    class="flex-1 min-h-0 flex flex-col items-center justify-center px-6 py-12"
    data-testid="provider-setup-tombstone"
  >
    <div class="w-full max-w-lg text-center">
      <div
        class="w-16 h-16 mx-auto mb-5 rounded-full bg-[var(--brand)]/15 flex items-center justify-center"
        aria-hidden="true"
      >
        <Icon icon="mdi:key-alert-outline" class="w-8 h-8 text-[var(--brand)]" />
      </div>
      <h1 class="text-2xl font-semibold txt-primary mb-3">
        {{ isAdmin ? $t('setupBanner.adminTitle') : $t('setupBanner.userTitle') }}
      </h1>
      <p class="txt-secondary text-base leading-relaxed mb-6">
        {{ isAdmin ? $t('setupBanner.adminText') : $t('setupBanner.userText') }}
      </p>
      <RouterLink
        v-if="isAdmin"
        to="/admin/setup"
        class="inline-flex items-center justify-center gap-2 btn-primary px-5 py-3 rounded-lg font-medium"
        data-testid="provider-setup-tombstone-cta"
      >
        <Icon icon="mdi:cog-outline" class="w-5 h-5" aria-hidden="true" />
        {{ $t('setupBanner.cta') }}
      </RouterLink>
      <a
        v-else
        :href="DOCS_URL"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center justify-center gap-2 btn-primary px-5 py-3 rounded-lg font-medium"
        data-testid="provider-setup-tombstone-docs"
      >
        <Icon icon="mdi:book-open-page-variant-outline" class="w-5 h-5" aria-hidden="true" />
        {{ $t('setupBanner.docsCta') }}
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'

/** Public docs for operators and users when chat has no usable AI provider. */
const DOCS_URL = 'https://docs.synaplan.com/'

/**
 * Full-page first-run gate when `setup.chatReady` is false. Admins get a
 * button to `/admin/setup`; everyone else is pointed at the public docs.
 * Not dismissable — sending chat messages would only produce a server error.
 */
const authStore = useAuthStore()
const config = useConfigStore()

const isAdmin = computed(() => authStore.isAdmin)
const visible = computed(() => authStore.isAuthenticated && config.setup.chatReady === false)
</script>
