<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { fetchLiveVoiceOptions, setLiveVoiceConsent } from '@/services/api/liveVoiceApi'
import { captionsPreferred, setCaptionsPreferred } from '@/services/liveVoice/preferences'

const { t } = useI18n()
const { success, error: notifyError } = useNotification()

const consentGiven = ref(false)
const saving = ref(false)

const captions = computed({
  get: () => captionsPreferred(),
  set: (on: boolean) => setCaptionsPreferred(on),
})

onMounted(async () => {
  try {
    const options = await fetchLiveVoiceOptions()
    consentGiven.value = options.consentGiven
  } catch {
    notifyError(t('settings.voice.loadFailed'))
  }
})

async function withdraw() {
  if (saving.value || !consentGiven.value) return
  saving.value = true
  try {
    await setLiveVoiceConsent(false)
    consentGiven.value = false
    success(t('settings.voice.withdrawn'))
  } catch {
    notifyError(t('settings.voice.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-4" data-testid="section-live-voice-preferences">
    <label class="flex items-center gap-2 text-sm txt-primary">
      <input v-model="captions" type="checkbox" data-testid="check-live-voice-captions" />
      {{ t('settings.voice.captions') }}
    </label>

    <div v-if="consentGiven" class="space-y-2">
      <p class="text-sm txt-secondary">{{ t('settings.voice.withdrawHint') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="saving"
        data-testid="btn-live-voice-withdraw"
        @click="withdraw"
      >
        {{ t('settings.voice.withdraw') }}
      </button>
    </div>
  </div>
</template>
