<template>
  <LookDeeperAction
    :effort="effort"
    :effort-notice="effortNotice"
    @look-deeper="emit('look-deeper')"
  />
  <ResearchDownload v-if="messageId" :message-id="messageId" :run-id="runId" />
</template>

<script setup lang="ts">
import LookDeeperAction from '@/components/research/LookDeeperAction.vue'
import ResearchDownload from '@/components/research/ResearchDownload.vue'

/**
 * The research controls in a reply's footer, left of the "…" menu: Look
 * deeper under a direct answer, Download under a finished research reply.
 * Each decides on its own whether it shows. The footer mounts this for
 * signed-in people only.
 */
defineProps<{
  messageId?: number
  runId?: string | null
  effort?: string | null
  effortNotice?: string | null
}>()

const emit = defineEmits<{ 'look-deeper': [] }>()
</script>
