<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/services/api/adminApi'
import { voiceCompany } from '@/services/liveVoice/company'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: notifyError } = useNotification()

type LiveRow = {
  sessionId: number
  userEmail: string
  provider: string
  seconds: number
  chatId?: number | null
}

const rows = ref<LiveRow[]>([])
const loading = ref(true)
const endingId = ref<number | null>(null)
const direct = ref(false)
const savingDirect = ref(false)

async function load() {
  loading.value = true
  try {
    const data = await adminApi.listLiveSessions()
    rows.value = data.sessions ?? []
    direct.value = data.webrtcOpenAi === true
  } catch {
    notifyError(t('admin.liveVoice.loadFailed'))
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function endSession(row: LiveRow) {
  const confirmed = await dialog.confirm({
    title: t('admin.liveVoice.killTitle'),
    message: t('admin.liveVoice.killMessage', {
      email: row.userEmail || t('admin.liveVoice.someone'),
    }),
    confirmText: t('admin.liveVoice.kill'),
    danger: true,
  })
  if (!confirmed) return
  endingId.value = row.sessionId
  try {
    await adminApi.killLiveSession(row.sessionId)
    rows.value = rows.value.filter((item) => item.sessionId !== row.sessionId)
    success(t('admin.liveVoice.killed'))
  } catch {
    notifyError(t('admin.liveVoice.killFailed'))
  } finally {
    endingId.value = null
  }
}

async function setDirect(enabled: boolean) {
  savingDirect.value = true
  try {
    const saved = await adminApi.setLiveVoiceWebRtc(enabled)
    direct.value = saved.enabled === true
    success(t('admin.liveVoice.directSaved'))
  } catch {
    notifyError(t('admin.liveVoice.directFailed'))
  } finally {
    savingDirect.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="surface-card rounded-lg p-6" data-testid="section-admin-live-voice">
    <h3 class="text-lg font-medium txt-primary">{{ t('admin.liveVoice.title') }}</h3>
    <p class="mt-1 text-sm txt-secondary">{{ t('admin.liveVoice.intro') }}</p>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
      <p class="max-w-xl text-sm txt-secondary">{{ t('admin.liveVoice.directHint') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="savingDirect"
        data-testid="btn-admin-live-voice-direct"
        @click="setDirect(!direct)"
      >
        {{ direct ? t('admin.liveVoice.directTurnOff') : t('admin.liveVoice.directTurnOn') }}
      </button>
    </div>

    <p v-if="loading" class="mt-4 text-sm txt-secondary">{{ t('admin.liveVoice.loading') }}</p>
    <p
      v-else-if="rows.length === 0"
      class="mt-4 text-sm txt-secondary"
      data-testid="state-admin-live-voice-empty"
    >
      {{ t('admin.liveVoice.empty') }}
    </p>

    <ul v-else class="mt-4 space-y-3">
      <li
        v-for="row in rows"
        :key="row.sessionId"
        class="flex flex-wrap items-center justify-between gap-3"
        data-testid="item-admin-live-voice"
      >
        <div class="min-w-0">
          <p class="truncate text-sm txt-primary">{{ row.userEmail }}</p>
          <p class="text-xs txt-secondary">
            {{ voiceCompany(row.provider) }} ·
            {{ t('admin.liveVoice.seconds', { seconds: row.seconds }) }}
          </p>
        </div>
        <button
          type="button"
          class="btn-danger rounded-lg px-4 py-2.5 text-sm font-medium disabled:opacity-50"
          :disabled="endingId === row.sessionId"
          data-testid="btn-admin-live-voice-end"
          @click="endSession(row)"
        >
          {{ t('admin.liveVoice.kill') }}
        </button>
      </li>
    </ul>
  </section>
</template>
