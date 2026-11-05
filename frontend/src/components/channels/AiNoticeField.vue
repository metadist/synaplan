<template>
  <section class="surface-card p-4 space-y-2" data-testid="section-ai-notice">
    <label class="block text-sm font-medium txt-primary" :for="fieldId">
      {{ t('channels.aiNoticeLabel') }}
    </label>
    <p class="text-xs txt-secondary">{{ t('channels.aiNoticeHelp') }}</p>
    <textarea
      :id="fieldId"
      v-model="text"
      rows="3"
      maxlength="500"
      :placeholder="placeholder"
      class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
      data-testid="input-ai-notice"
    />
    <p
      v-if="errorText"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="text-ai-notice-error"
    >
      {{ errorText }}
    </p>
    <button
      type="button"
      class="btn-primary px-4 py-2.5 text-sm font-medium"
      data-testid="btn-save-ai-notice"
      :disabled="saving"
      @click="save"
    >
      {{ t('channels.aiNoticeSave') }}
    </button>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { noticeError, readChannelNotice, saveChannelNotice } from '@/services/api/channelNoticeApi'

const props = defineProps<{
  channel: 'mail' | 'whatsapp' | 'telegram'
  bindingId: number
}>()

const { t } = useI18n()
const { success } = useNotification()
const text = ref('')
const placeholder = ref('')
const errorText = ref('')
const saving = ref(false)
const fieldId = `ai-notice-${props.channel}-${props.bindingId}`

onMounted(async () => {
  try {
    const notice = await readChannelNotice(props.channel, props.bindingId)
    text.value = notice.text
    placeholder.value = notice.defaultText
  } catch {
    placeholder.value = ''
  }
})

async function save(): Promise<void> {
  saving.value = true
  errorText.value = ''
  try {
    await saveChannelNotice(props.channel, props.bindingId, text.value.trim())
    success(t('channels.aiNoticeSaved'))
  } catch (error) {
    errorText.value = noticeError(error, t('channels.aiNoticeFailed'))
  } finally {
    saving.value = false
  }
}
</script>
