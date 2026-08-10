<script setup lang="ts">
/**
 * What the gateway actually runs server-side under the settings above.
 *
 * The mode dropdowns say "automatic" a lot, and automatic resolves against
 * things the operator cannot see from here (is a search provider configured? is
 * there a vision model?). This turns those switches into an answer.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  tools: string[]
  mcpServers: number
  mcpEnabled: boolean
}>()

const { t, te } = useI18n()

const labelled = computed(() =>
  props.tools.map((name) => ({
    name,
    // A tool added to the backend before this page learns its name still shows
    // up, just under its raw identifier.
    label: te(`messagesGateway.settings.activeTools.tools.${name}`)
      ? t(`messagesGateway.settings.activeTools.tools.${name}`)
      : name,
  }))
)

const mcpSummary = computed(() =>
  props.mcpEnabled && props.mcpServers > 0
    ? t(
        'messagesGateway.settings.activeTools.mcpServers',
        { count: props.mcpServers },
        props.mcpServers
      )
    : ''
)

const isEmpty = computed(() => 0 === labelled.value.length && '' === mcpSummary.value)
</script>

<template>
  <div class="mt-4 rounded-lg surface-chip px-4 py-3" data-testid="agents-active-tools">
    <p class="flex items-center gap-1.5 text-xs font-medium txt-primary">
      <Icon icon="heroicons:bolt" class="w-4 h-4 text-[var(--brand)]" />
      {{ $t('messagesGateway.settings.activeTools.title') }}
    </p>

    <p v-if="isEmpty" class="text-xs txt-secondary mt-2">
      {{ $t('messagesGateway.settings.activeTools.empty') }}
    </p>

    <div v-else class="flex flex-wrap items-center gap-2 mt-2">
      <span
        v-for="tool in labelled"
        :key="tool.name"
        class="inline-flex items-center gap-1.5 rounded-md surface-card px-2 py-1 text-xs txt-primary"
        :data-testid="`agents-active-tool-${tool.name}`"
      >
        {{ tool.label }}
        <code class="text-[0.7rem] txt-secondary">{{ tool.name }}</code>
      </span>
      <span v-if="mcpSummary" class="text-xs txt-secondary">{{ mcpSummary }}</span>
    </div>

    <p v-if="!isEmpty" class="text-xs txt-secondary mt-2 leading-relaxed">
      {{ $t('messagesGateway.settings.activeTools.hint') }}
    </p>
  </div>
</template>
