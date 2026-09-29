<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { isAdminPreview, type AdminPreviewFeature } from '@/composables/useAdminPreview'

/**
 * Renders its slot only for an admin. With `badge`, also shows a static
 * "Admin preview" label on the resource itself, so an admin can see that
 * other people do not see this yet.
 */
const props = withDefaults(
  defineProps<{
    feature: AdminPreviewFeature
    badge?: boolean
  }>(),
  { badge: false }
)

const { t } = useI18n()
const visible = computed(() => isAdminPreview(props.feature))
</script>

<template>
  <template v-if="visible">
    <span
      v-if="badge"
      class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border border-[var(--brand)]/30 bg-[var(--brand-alpha-light)] text-[var(--brand)]"
      data-testid="badge-admin-preview"
      :title="t('common.adminPreview.hint')"
    >
      {{ t('common.adminPreview.badge') }}
    </span>
    <slot />
  </template>
</template>
