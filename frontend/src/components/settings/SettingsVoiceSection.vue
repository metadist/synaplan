<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { saveDefaultModels } from '@/services/api/configApi'
import {
  fetchLiveVoiceOptions,
  setLiveVoiceConsent,
  type LiveVoiceModel,
} from '@/services/api/liveVoiceApi'
import { voiceCompany } from '@/services/liveVoice/company'
import {
  captionsPreferred,
  preferredVoice,
  setCaptionsPreferred,
  setPreferredVoice,
} from '@/services/liveVoice/preferences'

const { t } = useI18n()
const { success, error: notifyError } = useNotification()

const models = ref<LiveVoiceModel[]>([])
const modelId = ref<number | null>(null)
const voice = ref('')
const captions = ref(true)
const consentGiven = ref(false)
const loading = ref(true)
const saving = ref(false)

const fieldClass =
  'mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed'

const selected = computed(() => models.value.find((model) => model.id === modelId.value) ?? null)
const voices = computed(() => selected.value?.voices ?? [])

onMounted(async () => {
  captions.value = captionsPreferred()
  try {
    const options = await fetchLiveVoiceOptions()
    models.value = options.models ?? []
    modelId.value = options.defaultModelId ?? options.models?.[0]?.id ?? null
    consentGiven.value = options.consentGiven
    const provider = selected.value?.provider ?? ''
    const stored = provider ? preferredVoice(provider) : ''
    voice.value =
      stored && (selected.value?.voices ?? []).includes(stored)
        ? stored
        : (selected.value?.voice ?? '')
  } catch {
    notifyError(t('settings.voice.loadFailed'))
  } finally {
    loading.value = false
  }
})

function onModelChange() {
  const next = selected.value
  if (!next) return
  const stored = preferredVoice(next.provider ?? '')
  const listed = next.voices ?? []
  voice.value = stored && listed.includes(stored) ? stored : (listed[0] ?? '')
}

async function save() {
  if (modelId.value === null || saving.value) return
  saving.value = true
  try {
    await saveDefaultModels({ defaults: { VOICE_LIVE: modelId.value } })
    if (selected.value) setPreferredVoice(selected.value.provider ?? '', voice.value)
    setCaptionsPreferred(captions.value)
    success(t('settings.voice.saved'))
  } catch {
    notifyError(t('settings.voice.saveFailed'))
  } finally {
    saving.value = false
  }
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
      <p class="mt-1 text-sm txt-secondary">
        {{ t('settings.voice.intro', { company: voiceCompany(selected?.provider ?? '') }) }}
      </p>
    </div>

    <p v-if="loading" class="text-sm txt-secondary">{{ t('settings.voice.loading') }}</p>

    <div v-else class="space-y-4">
      <label class="block text-sm txt-primary">
        {{ t('settings.voice.model') }}
        <select
          v-model.number="modelId"
          :class="fieldClass"
          :disabled="models.length === 0 || saving"
          data-testid="select-live-voice-model"
          @change="onModelChange"
        >
          <option v-for="model in models" :key="model.id ?? model.name" :value="model.id">
            {{ model.name }} · {{ voiceCompany(model.provider ?? '') }}
          </option>
        </select>
      </label>

      <label class="block text-sm txt-primary">
        {{ t('settings.voice.voice') }}
        <select
          v-if="voices.length > 0"
          v-model="voice"
          :class="fieldClass"
          :disabled="saving"
          data-testid="select-live-voice-voice"
        >
          <option v-for="name in voices" :key="name" :value="name">{{ name }}</option>
        </select>
        <p v-else class="mt-1 text-sm txt-secondary">{{ t('settings.voice.noVoices') }}</p>
      </label>

      <label class="flex items-center gap-2 text-sm txt-primary">
        <input
          v-model="captions"
          type="checkbox"
          class="h-4 w-4"
          data-testid="check-live-voice-captions"
        />
        {{ t('settings.voice.captions') }}
      </label>

      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving || modelId === null"
        data-testid="btn-live-voice-settings-save"
        @click="save"
      >
        {{ t('settings.voice.save') }}
      </button>
    </div>

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
