<template>
  <div v-if="rows.length > 0" class="min-w-0" data-testid="file-sent-to">
    <p class="text-[10px] font-medium txt-secondary">{{ t('files.push.sentTo') }}</p>
    <p
      v-for="row in rows"
      :key="row.id"
      class="text-[10px] txt-primary truncate"
      :title="row.remote_path"
    >
      <span>{{ line(row) }}</span>
      <a
        v-if="row.open_url"
        :href="row.open_url"
        target="_blank"
        rel="noopener noreferrer"
        class="ml-1 text-[var(--brand)] hover:underline"
        @click.stop
      >
        {{ t('files.push.openIn', { name: appName(row.client) }) }}
      </a>
    </p>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import type { FileCopyRow } from '@/services/cloudFolderPushService'

defineProps<{
  rows: FileCopyRow[]
}>()

const { t, te } = useI18n()
const { formatRelativeTime } = useDateFormat()

function appName(client: string): string {
  const key = `files.push.kind.${client}`
  return te(key) ? t(key) : client
}

function line(row: FileCopyRow): string {
  const when = formatRelativeTime(new Date(row.created * 1000))
  const stateKey = `files.push.state.${row.state}`
  const state = te(stateKey) ? t(stateKey) : row.state
  const where = row.host !== '' ? `${appName(row.client)} · ${row.host}` : appName(row.client)
  return `${where} — ${state} — ${when}`
}
</script>
