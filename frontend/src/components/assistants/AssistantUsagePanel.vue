<template>
  <div class="surface-card rounded-xl p-4 space-y-3" data-testid="section-assistant-usage">
    <h3 class="txt-primary font-medium">{{ $t('assistants.usage') }}</h3>
    <p v-if="rows.length === 0" class="txt-secondary text-sm">{{ $t('assistants.usageEmpty') }}</p>
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="txt-secondary text-left">
            <th class="py-2 pr-3 font-medium">{{ $t('assistants.version') }}</th>
            <th class="py-2 pr-3 font-medium">{{ $t('assistants.messages') }}</th>
            <th class="py-2 pr-3 font-medium">{{ $t('assistants.tokens') }}</th>
            <th class="py-2 pr-3 font-medium">{{ $t('assistants.cost') }}</th>
            <th class="py-2 font-medium">{{ $t('assistants.users') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.agentVersionId ?? 'none'" class="txt-primary">
            <td class="py-2 pr-3">{{ row.version ?? '—' }}</td>
            <td class="py-2 pr-3">{{ row.messages }}</td>
            <td class="py-2 pr-3">{{ row.tokens }}</td>
            <td class="py-2 pr-3">{{ row.cost }}</td>
            <td class="py-2">{{ row.distinctUsers }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { AgentUsage } from '@/services/api/agentsApi'

defineProps<{
  rows: AgentUsage['byVersion']
}>()
</script>
