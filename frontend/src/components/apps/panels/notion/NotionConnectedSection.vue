<template>
  <div class="space-y-5">
    <div>
      <p class="txt-primary text-sm break-words" data-testid="text-notion-account">
        {{ accountLine }}
      </p>
      <p class="txt-secondary text-sm mt-1">{{ $t('apps.notion.scope') }}</p>
    </div>

    <div>
      <label class="inline-flex items-center gap-3 cursor-pointer">
        <span class="text-sm font-medium txt-primary">{{ $t('apps.notion.writeLabel') }}</span>
        <span class="relative inline-flex">
          <input
            type="checkbox"
            class="sr-only peer"
            :checked="status.allow_write"
            :disabled="saving"
            data-testid="toggle-notion-write"
            @change="onWrite"
          />
          <span
            class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand)] peer-disabled:opacity-50 peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--brand)] transition-colors"
          ></span>
          <span
            class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"
          ></span>
        </span>
      </label>
      <p class="txt-secondary text-sm mt-2" data-testid="text-notion-write-consequence">
        {{ status.allow_write ? $t('apps.notion.writeOn') : $t('apps.notion.writeOff') }}
      </p>
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
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { userFacingError } from '@/utils/userFacingError'
import NotionShareToggle from './NotionShareToggle.vue'
import {
  notionAppApi,
  type NotionAppAccount,
  type NotionAppStatus,
} from '@/services/api/notionAppApi'

const props = defineProps<{
  status: NotionAppStatus
  account: NotionAppAccount | null
}>()

const emit = defineEmits<{
  'update:status': [status: NotionAppStatus]
  try: [text: string]
}>()

const { t } = useI18n()
const { success, error } = useNotification()
const saving = ref(false)

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

const onWrite = async (event: Event) => {
  const input = event.target
  if (!(input instanceof HTMLInputElement)) return
  const next = input.checked
  saving.value = true
  try {
    emit('update:status', await notionAppApi.setWriting(next))
    success(next ? t('apps.notion.writeSavedOn') : t('apps.notion.writeSavedOff'))
  } catch (err) {
    input.checked = !next
    error(userFacingError(err, t('apps.notion.writeFailed')))
  } finally {
    saving.value = false
  }
}
</script>
