/**
 * Reactive online/offline status (Epic 7.3).
 *
 * Native uses @capacitor/network (the WebView's `navigator.onLine` is
 * unreliable inside Capacitor); web uses `navigator.onLine` + the online/offline
 * window events. The listener is wired once and shared via a module-level ref.
 */
import { ref } from 'vue'
import { isNativeApp } from '@/services/api/nativeRuntime'

const isOnline = ref(true)
let initialized = false

export function useNetworkStatus() {
  if (!initialized) {
    initialized = true
    void initNetworkMonitoring()
  }
  return { isOnline }
}

async function initNetworkMonitoring(): Promise<void> {
  if (isNativeApp()) {
    try {
      const { Network } = await import('@capacitor/network')
      const status = await Network.getStatus()
      isOnline.value = status.connected
      await Network.addListener('networkStatusChange', (status) => {
        isOnline.value = status.connected
      })
      return
    } catch {
      // Fall through to the web heuristic if the plugin is unavailable.
    }
  }

  isOnline.value = navigator.onLine
  window.addEventListener('online', () => {
    isOnline.value = true
  })
  window.addEventListener('offline', () => {
    isOnline.value = false
  })
}
