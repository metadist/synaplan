<template>
  <component
    :is="removable ? 'button' : 'span'"
    :type="removable ? 'button' : undefined"
    class="tool-badge"
    :class="[`tool-badge--${meta.variant}`, { 'tool-badge--static': !removable }]"
    :title="removable ? $t('chatInput.removeTool') : undefined"
    :aria-label="removable ? $t('chatInput.removeTool') : undefined"
    :data-testid="removable ? 'chip-active-tool' : 'badge-message-tool'"
    @click="handleClick"
  >
    <Icon :icon="meta.icon" class="w-4 h-4 flex-shrink-0" />
    <span class="font-medium leading-none">{{ $t(meta.labelKey) }}</span>
    <XMarkIcon v-if="removable" class="tool-badge__x w-4 h-4 flex-shrink-0" />
  </component>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

type Tool = 'search' | 'pic' | 'vid'

interface ToolMeta {
  variant: 'search' | 'image' | 'video'
  icon: string
  labelKey: string
}

const props = withDefaults(defineProps<{ tool: Tool; removable?: boolean }>(), {
  removable: true,
})

const emit = defineEmits<{ remove: [] }>()

const toolMeta: Record<Tool, ToolMeta> = {
  search: { variant: 'search', icon: 'mdi:magnify', labelKey: 'chatInput.tools.badgeSearch' },
  pic: { variant: 'image', icon: 'mdi:image', labelKey: 'chatInput.tools.badgeImage' },
  vid: { variant: 'video', icon: 'mdi:video', labelKey: 'chatInput.tools.badgeVideo' },
}

const meta = computed(() => toolMeta[props.tool])

const handleClick = () => {
  if (props.removable) emit('remove')
}
</script>
