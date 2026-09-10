<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Shared notice for a feature module this installation does not provide.
 * Rendered in place of a module-owned surface (FM15) and wherever a request
 * came back as the gate's `404 feature_not_configured` (FM17), so the sentence
 * exists once. Admins additionally get the way to the Feature status page,
 * which lists what configures the module; a docs anchor, when known, links to
 * the enable guide.
 */
const props = defineProps<{
  module: string
  docs?: string | null
}>()

const DOCS_URL = 'https://docs.synaplan.com/'

const { t, te } = useI18n()
const authStore = useAuthStore()

const label = computed(() => {
  const key = `modules.${props.module}.label`
  return te(key) ? t(key) : props.module
})

const docsHref = computed(() => (props.docs ? `${DOCS_URL}${props.docs.replace(/^\//, '')}` : null))
</script>

<template>
  <div
    class="surface-card p-6 flex items-start gap-4"
    role="status"
    data-testid="notice-feature-not-configured"
    :data-module="module"
  >
    <Icon icon="mdi:puzzle-outline" class="w-6 h-6 txt-secondary flex-shrink-0 mt-0.5" />
    <div class="min-w-0 space-y-2">
      <h3 class="text-base font-semibold txt-primary">
        {{ t('modules.notice.title', { module: label }) }}
      </h3>
      <p class="text-sm txt-secondary">{{ t('modules.notice.body') }}</p>
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <a
          v-if="docsHref"
          :href="docsHref"
          target="_blank"
          rel="noopener noreferrer"
          class="text-[var(--brand)] hover:underline inline-flex items-center gap-1"
          data-testid="link-feature-docs"
        >
          {{ t('modules.notice.howToEnable') }}
          <Icon icon="mdi:open-in-new" class="w-4 h-4" />
        </a>
        <RouterLink
          v-if="authStore.isAdmin"
          to="/admin/features"
          class="text-[var(--brand)] hover:underline"
          data-testid="link-feature-status"
        >
          {{ t('modules.notice.openFeatureStatus') }}
        </RouterLink>
      </div>
    </div>
  </div>
</template>
