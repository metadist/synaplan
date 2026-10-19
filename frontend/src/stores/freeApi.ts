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

export const FREEAPI_MODULE_ID = 'freeapi'

/**
 * The Synaplan Demo on this install. Starting and leaving move the default
 * models, so both refresh the runtime config (chat readiness) and the model
 * defaults before they resolve.
 */
export const useFreeApiStore = defineStore('freeApi', () => {
  const status = ref<FreeApiStatus | null>(null)
  const busy = ref(false)

  function isAvailable(): boolean {
    return isModuleExplicitlyConfigured(FREEAPI_MODULE_ID)
  }

  async function load(): Promise<void> {
    if (!isAvailable()) {
      status.value = null
      return
    }
    try {
      status.value = await getFreeApiStatus()
    } catch {
      status.value = null
    }
  }

  /** Throws the API error, so the surface can say why nothing was started. */
  async function start(locale: string, force = false): Promise<void> {
    busy.value = true
    try {
      status.value = await activateFreeApi({ consent: true, locale, force })
      await refreshAfterChange()
    } finally {
      busy.value = false
    }
  }

  async function leave(): Promise<FreeApiLeaveResult> {
    busy.value = true
    try {
      const result = await leaveFreeApi()
      status.value = result.status
      await refreshAfterChange()
      return result
    } finally {
      busy.value = false
    }
  }

  /**
   * Never throws: the change itself already happened, and a failed refresh
   * must not read as "nothing was changed". A page reload shows the new state.
   */
  async function refreshAfterChange(): Promise<void> {
    try {
      await useConfigStore().reload()
      const aiConfig = useAiConfigStore()
      await Promise.all([aiConfig.loadModels(), aiConfig.loadDefaults()])
    } catch {
      // Deliberately ignored, see above.
    }
  }

  return { status, busy, isAvailable, load, start, leave }
})
