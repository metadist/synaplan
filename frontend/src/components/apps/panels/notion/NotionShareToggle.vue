<template>
  <div data-testid="section-notion-sharing">
    <label class="inline-flex items-center gap-3 cursor-pointer">
      <span class="text-sm font-medium txt-primary">{{ $t('apps.notion.sharing.label') }}</span>
      <span class="relative inline-flex">
        <input
          type="checkbox"
          class="sr-only peer"
          :checked="shared"
          :disabled="saving"
          data-testid="toggle-notion-share"
          @change="onChange"
        />
        <span
          class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand)] peer-disabled:opacity-50 peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--brand)] transition-colors"
        ></span>
        <span
          class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"
        ></span>
      </span>
    </label>
    <p class="txt-secondary text-sm mt-2" data-testid="text-notion-share-consequence">
      {{ shared ? $t('apps.notion.sharing.on') : $t('apps.notion.sharing.off') }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { notionAppApi, type NotionAppStatus } from '@/services/api/notionAppApi'
import { userFacingError } from '@/utils/userFacingError'

defineProps<{ shared: boolean }>()
const emit = defineEmits<{ saved: [status: NotionAppStatus] }>()

const { t } = useI18n()
const { success, error } = useNotification()
const saving = ref(false)

const onChange = async (event: Event) => {
  const input = event.target
  if (!(input instanceof HTMLInputElement)) return
  const next = input.checked
  saving.value = true
  try {
    emit('saved', await notionAppApi.setShared(next))
    success(next ? t('apps.notion.sharing.savedOn') : t('apps.notion.sharing.savedOff'))
  } catch (err) {
    input.checked = !next
    error(userFacingError(err, t('apps.notion.sharing.failed')))
  } finally {
    saving.value = false
  }
}
</script>
