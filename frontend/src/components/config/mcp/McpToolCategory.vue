<template>
  <section class="py-2" :data-testid="`mcp-tool-category-${category}`">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <button
        type="button"
        class="flex items-center gap-2 txt-primary text-sm font-medium"
        :aria-expanded="open"
        :data-testid="`btn-mcp-category-${category}`"
        @click="open = !open"
      >
        <Icon
          icon="heroicons:chevron-right"
          class="w-4 h-4 transition-transform"
          :class="{ 'rotate-90': open }"
          aria-hidden="true"
        />
        {{ $t(`mcpServers.permissions.category.${category}`) }} ({{ tools.length }})
      </button>
      <ToolOutcomeSelect
        v-if="category !== 'unavailable'"
        :model-value="categoryOutcome(tools)"
        :disabled="disabled || editableTools(tools).length === 0"
        :label="
          $t('mcpServers.permissions.categoryLabel', {
            category: $t(`mcpServers.permissions.category.${category}`),
          })
        "
        :testid="`select-mcp-category-${category}`"
        @update:model-value="emit('set-category', $event)"
      />
    </div>
    <ul v-if="open" class="divide-y divide-light-border/30 dark:divide-dark-border/20 mt-1">
      <McpToolRow
        v-for="tool in tools"
        :key="tool.name"
        :tool="tool"
        :disabled="disabled"
        :is-new="newNames.has(tool.name)"
        @change="emit('set-tool', tool, $event)"
      />
    </ul>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import type { McpToolPermission } from '@/services/api/mcpServersApi'
import McpToolRow from './McpToolRow.vue'
import ToolOutcomeSelect from './ToolOutcomeSelect.vue'
import {
  categoryOutcome,
  editableTools,
  type ToolCategory,
  type ToolOutcome,
} from './toolPermissions'

const props = defineProps<{
  category: ToolCategory
  tools: McpToolPermission[]
  disabled?: boolean
  newNames: Set<string>
}>()

const emit = defineEmits<{
  'set-tool': [tool: McpToolPermission, outcome: ToolOutcome | 'default']
  'set-category': [outcome: ToolOutcome]
}>()

const open = ref(props.category !== 'unavailable')
</script>
