<template>
  <div class="mt-4 space-y-3" data-testid="section-notion-shared-permissions">
    <button
      type="button"
      class="btn-secondary px-4 py-2.5 text-sm font-medium"
      :aria-expanded="open"
      data-testid="btn-notion-shared-permissions"
      @click="open = !open"
    >
      {{
        open ? $t('apps.notion.sharing.hidePermissions') : $t('apps.notion.sharing.showPermissions')
      }}
    </button>
    <McpToolPermissions v-if="open" :server-id="serverId" server-name="Notion" />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import McpToolPermissions from '@/components/config/mcp/McpToolPermissions.vue'

defineProps<{ serverId: number }>()

const open = ref(false)
</script>
