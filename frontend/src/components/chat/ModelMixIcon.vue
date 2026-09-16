<template>
  <img
    v-if="icon.kind === 'brand'"
    :src="iconSrc"
    :style="pxStyle"
    class="object-contain flex-shrink-0"
    alt=""
    aria-hidden="true"
  />
  <ServiceIcon
    v-else-if="icon.kind === 'service'"
    :service="icon.service"
    :size="size"
    :show-flag="false"
  />
  <Icon v-else :icon="icon.icon" :style="pxStyle" class="flex-shrink-0" aria-hidden="true" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useTheme } from '@/composables/useTheme'
import type { ModelMixIcon } from '@/utils/modelMixes'

/**
 * The face of a model mix: the install's brand icon for the default mix
 * (white-label safe), a provider logo for provider mixes, or a plain Iconify
 * icon (EU flag for the Europe mix).
 */
const props = withDefaults(defineProps<{ icon: ModelMixIcon; size?: number }>(), {
  size: 20,
})

const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)

const pxStyle = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }))
</script>
