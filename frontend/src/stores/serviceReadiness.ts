import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { setFeatureStatusCounts } from '@/composables/useNavItems'
import { useNotification } from '@/composables/useNotification'
import { useTour } from '@/composables/useTour'
import { applyProviderDefaults } from '@/services/api/providerKeysApi'
import { getFeaturesStatus, type ServiceReadiness } from '@/services/featuresService'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { useFreeApiStore } from '@/stores/freeApi'
import { SERVICES_READY_TOAST_SEEN_ID, SERVICES_STARTING_TOAST_SEEN_ID } from '@/tours'
import { serviceIsInProgress, serviceNeedsAttention } from '@/utils/serviceReadiness'

const POLL_MS = 5000
const SWITCH_TOAST_MS = 12000

async function switchToLocalModels(): Promise<void> {
  const { success, error } = useNotification()
  const t = i18n.global.t
  try {
    await useFreeApiStore().leave()
    await applyProviderDefaults('ollama')
    await useConfigStore().reload()
    const aiConfig = useAiConfigStore()
    await Promise.all([aiConfig.loadModels(), aiConfig.loadDefaults()])
    success(t('settings.features.services.toast.switchedToLocal'))
  } catch {
    error(t('settings.features.services.toast.switchFailed'))
  }
}

async function announceReadinessToasts(
  running: ServiceReadiness[],
  hasInProgress: boolean,
  hasAttention: boolean,
  sawInProgress: { value: boolean }
): Promise<void> {
  if (running.length === 0) return

  const { seen, ensureLoaded, markSeen } = useTour()
  await ensureLoaded()
  const { push, info } = useNotification()
  const t = i18n.global.t

  if (hasInProgress) {
    sawInProgress.value = true
    if (
      !seen.value.includes(SERVICES_STARTING_TOAST_SEEN_ID) &&
      !seen.value.includes(SERVICES_READY_TOAST_SEEN_ID)
    ) {
      await markSeen(SERVICES_STARTING_TOAST_SEEN_ID)
      info(t('settings.features.services.toast.starting'))
    }
    return
  }

  if (hasAttention || !running.every((s) => s.state === 'ready')) return
  if (seen.value.includes(SERVICES_READY_TOAST_SEEN_ID)) return

  const shouldAnnounce = sawInProgress.value || seen.value.includes(SERVICES_STARTING_TOAST_SEEN_ID)
  await markSeen(SERVICES_READY_TOAST_SEEN_ID)
  if (!shouldAnnounce) return

  const freeApi = useFreeApiStore()
  if (freeApi.isAvailable() && freeApi.status === null) await freeApi.load()
  const ollamaReady = running.some((s) => s.id === 'ollama' && s.state === 'ready')
  if (ollamaReady && freeApi.status?.active === true) {
    push({
      type: 'success',
      message: t('settings.features.services.toast.allReady'),
      duration: SWITCH_TOAST_MS,
      action: {
        label: t('settings.features.services.toast.switchToLocal'),
        onClick: () => void switchToLocalModels(),
      },
    })
    return
  }
  push({ type: 'success', message: t('settings.features.services.toast.allReady') })
}

/**
 * Shared service-readiness state for the "Still setting up" pill, the two
 * onboarding toasts, `/setup/status` and the welcome screen's Ollama tile
 * (plan §5.10, C15). Admin-only: non-admins never see service internals.
 */
export const useServiceReadinessStore = defineStore('serviceReadiness', () => {
  const services = ref<ServiceReadiness[]>([])
  const loaded = ref(false)
  const loadFailed = ref(false)
  let pendingLoad: Promise<void> | null = null
  let timer: ReturnType<typeof setInterval> | null = null
  let consumers = 0
  const sawInProgress = ref(false)

  const runningServices = computed(() => services.value.filter((s) => s.state !== 'off'))
  const hasInProgress = computed(() => runningServices.value.some(serviceIsInProgress))
  const hasAttention = computed(() => runningServices.value.some(serviceNeedsAttention))
  const sawInProgressThisSession = computed(() => sawInProgress.value)

  const canRead = (): boolean => {
    const auth = useAuthStore()
    return auth.isAuthenticated && auth.isAdmin
  }

  const stopTimer = (): void => {
    if (timer) clearInterval(timer)
    timer = null
  }

  async function refresh(): Promise<void> {
    if (!canRead()) {
      services.value = []
      loaded.value = false
      loadFailed.value = false
      stopTimer()
      return
    }
    if (pendingLoad) return pendingLoad
    pendingLoad = (async () => {
      try {
        const status = await getFeaturesStatus()
        services.value = status.services ?? []
        loaded.value = true
        loadFailed.value = false
        setFeatureStatusCounts(status)
        await announceReadinessToasts(
          runningServices.value,
          hasInProgress.value,
          hasAttention.value,
          sawInProgress
        )
        if (!hasInProgress.value) stopTimer()
      } catch {
        loadFailed.value = true
      } finally {
        pendingLoad = null
      }
    })()
    return pendingLoad
  }

  const startTimer = (): void => {
    if (timer || !canRead()) return
    timer = setInterval(() => {
      if (consumers <= 0 || !hasInProgress.value) stopTimer()
      else void refresh()
    }, POLL_MS)
  }

  const acquire = (): void => {
    consumers += 1
    void refresh().then(() => {
      if (consumers > 0 && hasInProgress.value) startTimer()
    })
  }

  const release = (): void => {
    consumers = Math.max(0, consumers - 1)
    if (consumers === 0) stopTimer()
  }

  const resetForTests = (): void => {
    stopTimer()
    services.value = []
    loaded.value = false
    loadFailed.value = false
    pendingLoad = null
    consumers = 0
    sawInProgress.value = false
  }

  return {
    services,
    loaded,
    loadFailed,
    hasInProgress,
    hasAttention,
    sawInProgressThisSession,
    refresh,
    switchToLocalModels,
    acquire,
    release,
    resetForTests,
  }
})
