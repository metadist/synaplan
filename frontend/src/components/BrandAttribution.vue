<template>
  <span v-if="config.branding.showPoweredBy" class="inline-flex items-center gap-1 flex-wrap">
    <template v-if="distinctBrand">
      <a
        :href="config.branding.homepageUrl"
        target="_blank"
        rel="noopener noreferrer"
        :class="props.linkClass"
        data-testid="brand-attribution-brand"
        >{{ config.branding.name }}</a
      >
      <span aria-hidden="true">·</span>
    </template>
    <span>{{ $t('branding.poweredBy') }}</span>
    <a
      :href="config.branding.poweredByUrl"
      target="_blank"
      rel="noopener noreferrer"
      :class="props.linkClass"
      data-testid="brand-attribution-powered-by"
      >{{ config.branding.poweredByLabel }}</a
    >
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useConfigStore } from '@/stores/config'

const props = withDefaults(
  defineProps<{
    /** Tailwind classes applied to the links (lets each surface match its style). */
    linkClass?: string
  }>(),
  {
    linkClass: 'font-medium hover:underline',
  }
)

const config = useConfigStore()

// Collapse "<name> · powered by <label>" to just "powered by <label>" when the
// brand IS the platform (default Synaplan deployment) so the default reads
// "Powered by Synaplan" exactly like before this epic.
const distinctBrand = computed(
  () =>
    config.branding.name.trim().toLowerCase() !==
    config.branding.poweredByLabel.trim().toLowerCase()
)
</script>
