<template>
  <div class="space-y-5">
    <div>
      <p class="txt-primary text-sm break-words" data-testid="text-notion-account">
        {{ accountLine }}
      </p>
      <p class="txt-secondary text-sm mt-1">{{ $t('apps.notion.scope') }}</p>
    </div>

    <NotionShareToggle
      v-if="status.sharing.can_share"
      :shared="status.sharing.shared_on"
      @saved="emit('update:status', $event)"
    />

    <div>
      <p class="text-sm font-medium txt-primary">{{ $t('apps.notion.examplesTitle') }}</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          v-for="example in examples"
          :key="example.id"
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          :data-testid="`btn-notion-example-${example.id}`"
          @click="emit('try', example.text)"
        >
          {{ example.text }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NotionShareToggle from './NotionShareToggle.vue'
import type { NotionAppAccount, NotionAppStatus } from '@/services/api/notionAppApi'

const props = defineProps<{
  status: NotionAppStatus
  account: NotionAppAccount | null
}>()

const emit = defineEmits<{
  'update:status': [status: NotionAppStatus]
  try: [text: string]
}>()

const { t } = useI18n()

const examples = computed(() => [
  { id: 'read', text: t('apps.notion.exampleRead') },
  { id: 'meetings', text: t('apps.notion.exampleMeetings') },
  { id: 'database', text: t('apps.notion.exampleDatabase') },
  { id: 'write', text: t('apps.notion.exampleWrite') },
])

const accountLine = computed(() => {
  const name = props.account?.user_name
  const email = props.account?.user_email
  const who = name && email ? `${name} (${email})` : (name ?? email)
  const workspace = props.account?.workspace_name
  if (who && workspace) return t('apps.notion.connectedAs', { workspace, account: who })
  if (who) return t('apps.notion.connectedAsAccount', { account: who })
  return t('apps.notion.connected')
})
</script>
