<template>
  <li
    class="py-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
    :data-testid="`mcp-tool-${tool.name}`"
  >
    <div class="min-w-0 flex-1">
      <p class="txt-primary text-sm font-medium break-words">
        {{ tool.title }}
        <span
          v-if="isNew"
          class="ml-2 text-xs px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400"
          :data-testid="`mcp-tool-new-${tool.name}`"
          >{{ $t('mcpServers.permissions.new') }}</span
        >
        <span v-if="changed" class="ml-2 txt-secondary text-xs">{{
          $t('mcpServers.permissions.changed')
        }}</span>
      </p>
      <p
        v-if="tool.description"
        class="txt-secondary text-sm mt-1 break-words"
        :class="{ 'line-clamp-2': !expanded }"
      >
        {{ tool.description }}
      </p>
      <button
        v-if="tool.description.length > 140 || tool.input_count > 0"
        type="button"
        class="txt-secondary text-xs underline mt-1"
        :aria-expanded="expanded"
        :data-testid="`btn-mcp-tool-more-${tool.name}`"
        @click="expanded = !expanded"
      >
        {{
          expanded ? $t('mcpServers.permissions.showLess') : $t('mcpServers.permissions.showMore')
        }}
      </button>
      <p v-if="expanded && tool.input_count > 0" class="txt-secondary text-xs mt-1 break-words">
        {{ $t('mcpServers.permissions.inputs', { count: tool.input_count }) }}.
        {{ $t('mcpServers.permissions.inputNames', { names: tool.input_names.join(', ') }) }}
      </p>
      <p v-if="!tool.hinted && tool.category !== 'unavailable'" class="txt-secondary text-xs mt-1">
        {{ $t('mcpServers.permissions.noHints') }}
      </p>
      <p
        v-if="tool.locked_reason"
        class="txt-secondary text-xs mt-1"
        :data-testid="`mcp-tool-locked-${tool.name}`"
      >
        {{ $t(`mcpServers.permissions.locked.${tool.locked_reason}`) }}
      </p>
    </div>
    <ToolOutcomeSelect
      :model-value="tool.outcome"
      :disabled="disabled || tool.locked_reason !== null"
      :label="$t('mcpServers.permissions.toolLabel', { tool: tool.title })"
      :testid="`select-mcp-tool-${tool.name}`"
      @update:model-value="emit('change', $event)"
    />
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import type { McpToolPermission } from '@/services/api/mcpServersApi'
import ToolOutcomeSelect from './ToolOutcomeSelect.vue'
import type { ToolOutcome } from './toolPermissions'

const props = defineProps<{
  tool: McpToolPermission
  disabled?: boolean
  /** Shown as New for this view even after the server marked it seen. */
  isNew: boolean
}>()

const emit = defineEmits<{ change: [outcome: ToolOutcome] }>()

const expanded = ref(false)
const changed = computed(() => props.tool.outcome !== props.tool.default_outcome)
</script>
