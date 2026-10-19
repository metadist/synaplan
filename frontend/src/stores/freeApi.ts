import { defineStore } from 'pinia'
import { ref } from 'vue'
import { isModuleExplicitlyConfigured } from '@/composables/useModuleFeature'
import {
  activateFreeApi,
  getFreeApiStatus,
  leaveFreeApi,
  type FreeApiLeaveResult,
  type FreeApiStatus,
} from '@/services/api/freeApiApi'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useConfigStore } from '@/stores/config'
import { useModelMixStore } from '@/stores/modelMix'
import { DEMO_MIX_ID } from '@/utils/modelMixes'

export const FREEAPI_MODULE_ID = 'freeapi'

/**
 * The Synaplan Demo on this install. Starting and leaving move the default
 * models, so both refresh the runtime config (chat readiness) and the model
 * defaults before they resolve.
 */
export const useFreeApiStore = defineStore('freeApi', () => {
  const status = ref<FreeApiStatus | null>(null)
  const busy = ref(false)
  let pendingLoad: Promise<void> | null = null

  function isAvailable(): boolean {
    return isModuleExplicitlyConfigured(FREEAPI_MODULE_ID)
  }

  function setStatus(next: FreeApiStatus | null): void {
    status.value = next
    if (next?.active === false) useModelMixStore().forgetMix(DEMO_MIX_ID)
  }

  /** Surfaces mounted together share one request. */
  function load(): Promise<void> {
    if (!isAvailable()) {
      status.value = null
      return Promise.resolve()
    }
    pendingLoad ??= getFreeApiStatus()
      .then(setStatus)
      .catch(() => {
        // Keep the status already shown. A failed refresh must not look like
        // the Demo turned off; the next successful load replaces it.
      })
      .finally(() => {
        pendingLoad = null
      })
    return pendingLoad
  }

  /** Throws the API error, so the surface can say why nothing was started. */
  async function start(locale: string, force = false): Promise<void> {
    busy.value = true
    try {
      setStatus(await activateFreeApi({ consent: true, locale, force }))
      await refreshAfterChange()
    } finally {
      busy.value = false
    }
  }

  async function leave(): Promise<FreeApiLeaveResult & { configRefreshed: boolean }> {
    busy.value = true
    try {
      const result = await leaveFreeApi()
      setStatus(result.status)
      const configRefreshed = await refreshAfterChange()
      return { ...result, configRefreshed }
    } finally {
      busy.value = false
    }
  }

  /** The server left the Demo on its own (an own provider was connected). */
  async function syncAfterAutoLeave(): Promise<void> {
    await load()
    await refreshAfterChange()
  }

  /**
   * Never throws: the change itself already happened, and a failed refresh
   * must not read as "nothing was changed". A page reload shows the new state.
   *
   * True only after the runtime config reloaded, so chat readiness is the
   * state after the change. Model-list failures still count as refreshed.
   */
  async function refreshAfterChange(): Promise<boolean> {
    let configRefreshed = false
    try {
      await useConfigStore().reload()
      configRefreshed = true
      const aiConfig = useAiConfigStore()
      await Promise.all([aiConfig.loadModels(), aiConfig.loadDefaults()])
    } catch {
      // Deliberately ignored, see above.
    }
    return configRefreshed
  }

  return { status, busy, isAvailable, load, start, leave, syncAfterAutoLeave }
})
