<template>
  <section id="legal" class="surface-card rounded-lg p-6 scroll-mt-6" data-testid="section-legal">
    <h2 class="text-xl font-semibold txt-primary mb-6 flex items-center gap-2">
      <Icon icon="mdi:shield-check" class="w-5 h-5" />
      {{ $t('profile.legal.title') }}
    </h2>
    <div class="flex flex-col gap-3">
      <a
        :href="config.branding.privacyUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
        data-testid="link-privacy"
      >
        <Icon icon="mdi:lock-outline" class="w-5 h-5" />
        {{ $t('profile.legal.privacy') }}
        <Icon icon="mdi:open-in-new" class="w-4 h-4 txt-secondary" />
      </a>
      <a
        :href="config.branding.termsUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
        data-testid="link-terms"
      >
        <Icon icon="mdi:file-document-outline" class="w-5 h-5" />
        {{ $t('profile.legal.terms') }}
        <Icon icon="mdi:open-in-new" class="w-4 h-4 txt-secondary" />
      </a>
      <a
        v-if="isExternalDeletionUrl"
        :href="config.branding.accountDeletionUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
        data-testid="link-account-deletion"
      >
        <Icon icon="mdi:account-remove-outline" class="w-5 h-5" />
        {{ $t('profile.legal.accountDeletion') }}
        <Icon icon="mdi:open-in-new" class="w-4 h-4 txt-secondary" />
      </a>
      <RouterLink
        v-else
        :to="config.branding.accountDeletionUrl"
        class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
        data-testid="link-account-deletion"
      >
        <Icon icon="mdi:account-remove-outline" class="w-5 h-5" />
        {{ $t('profile.legal.accountDeletion') }}
      </RouterLink>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'

const config = useConfigStore()

const isExternalDeletionUrl = computed(() =>
  /^https?:\/\//i.test(config.branding.accountDeletionUrl)
)
</script>
