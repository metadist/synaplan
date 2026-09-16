import { computed, ref } from 'vue'
import { desktopApi, type DesktopDevice } from '@/services/api/desktopApi'
import { isDesktopAgentEnabled } from './useDesktopAgentFeature'

/**
 * Shared list of the current user's paired computers.
 *
 * Module-level state (a tiny store) so the two surfaces that care about it stay
 * in sync without re-fetching: the Channels → Desktop page owns the list and
 * mutates it (pair/revoke), while the chat composer only reads
 * {@link hasActiveDevices} to decide whether to offer "Run on this computer"
 * (DS16). Revoking the last computer on the page therefore hides the composer
 * action immediately, with no second request.
 *
 * Every call is a no-op when the DESKTOP_AGENT flag is off (the endpoints 404),
 * so callers never need to guard the flag themselves.
 */
const devices = ref<DesktopDevice[]>([])
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)

const activeDevices = computed(() => devices.value.filter((d) => d.status === 'active'))
const hasActiveDevices = computed(() => activeDevices.value.length > 0)

async function reload(): Promise<void> {
  if (!isDesktopAgentEnabled()) {
    devices.value = []
    loaded.value = true
    return
  }
  loading.value = true
  error.value = null
  try {
    devices.value = await desktopApi.listDevices()
    loaded.value = true
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to load computers'
  } finally {
    loading.value = false
  }
}

/**
 * Load the list once (idempotent). The composer calls this on mount to know
 * whether to show its action without forcing a refetch on every open.
 */
async function ensureLoaded(): Promise<void> {
  if (loaded.value || loading.value) return
  await reload()
}

export function useDesktopDevices() {
  return {
    devices,
    activeDevices,
    hasActiveDevices,
    loading,
    error,
    reload,
    ensureLoaded,
  }
}
