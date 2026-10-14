<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { fetchLiveVoiceOptions, setLiveVoiceConsent } from '@/services/api/liveVoiceApi'
import { captionsPreferred, setCaptionsPreferred } from '@/services/liveVoice/preferences'

const { t } = useI18n()
const { success, error: notifyError } = useNotification()

const captions = ref(true)
const consentGiven = ref(false)
const saving = ref(false)

onMounted(async () => {
  captions.value = captionsPreferred()
  try {
    const options = await fetchLiveVoiceOptions()
    consentGiven.value = options.consentGiven
  } catch {
    notifyError(t('settings.voice.loadFailed'))
  }
})

function onCaptionsChange() {
  setCaptionsPreferred(captions.value)
}

async function withdraw() {
  if (saving.value) return
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
  <section id="voice" class="space-y-6 scroll-mt-24" data-testid="page-voice-settings">
    <div>
      <h2 class="text-lg font-medium txt-primary">{{ t('settings.voice.title') }}</h2>
      <i18n-t
        keypath="settings.voice.modelsHint"
        tag="p"
        scope="global"
        class="mt-1 text-sm txt-secondary"
      >
        <template #page>
          <RouterLink
            to="/ai/models?highlight=VOICE_LIVE"
            class="text-[var(--brand)] hover:underline"
            data-testid="link-live-voice-models"
          >
            {{ t('nav.configAiModels') }}
          </RouterLink>
        </template>
      </i18n-t>
    </div>

    <label class="flex items-center gap-2 text-sm txt-primary">
      <input
        v-model="captions"
        type="checkbox"
        class="h-4 w-4"
        data-testid="check-live-voice-captions"
        @change="onCaptionsChange"
      />
      {{ t('settings.voice.captions') }}
    </label>

    <div v-if="consentGiven" class="space-y-2">
      <p class="text-sm txt-secondary">{{ t('settings.voice.withdrawHint') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:opacity-50"
        :disabled="saving"
        data-testid="btn-live-voice-withdraw"
        @click="withdraw"
      >
        {{ t('settings.voice.withdraw') }}
      </button>
    </div>
  </section>
</template>
