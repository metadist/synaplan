/**
 * Android hardware back-button handling (Epic 9.5 / Guideline 4.2).
 *
 * Without this, Android's system back button either does nothing inside the
 * WebView SPA or abruptly closes the app — both read as "not a real app". We
 * instead navigate back through the in-app router history when possible, and at
 * a root view require a confirming second press within a short window before
 * exiting (the familiar "press back again to exit" pattern).
 *
 * iOS has no hardware back button, so this is Android-only. No-op on web.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

let registered = false
let lastBackPress = 0
const EXIT_CONFIRM_WINDOW_MS = 2000

export async function initNativeBackButton(): Promise<void> {
  if (registered || !isNativeApp()) {
    return
  }

  const { Capacitor } = await import('@capacitor/core')
  if (Capacitor.getPlatform() !== 'android') {
    return
  }
  registered = true

  const { App: CapacitorApp } = await import('@capacitor/app')
  const router = (await import('@/router')).default
  const { useNotification } = await import('@/composables/useNotification')
  const { i18n } = await import('@/i18n')

  await CapacitorApp.addListener('backButton', ({ canGoBack }) => {
    // Pop in-app history while there is somewhere to go back to.
    if (canGoBack) {
      router.back()
      return
    }
    // Root view: require a confirming second press before exiting.
    const now = Date.now()
    if (now - lastBackPress < EXIT_CONFIRM_WINDOW_MS) {
      void CapacitorApp.exitApp()
      return
    }
    lastBackPress = now
    useNotification().info(i18n.global.t('native.exitHint'), EXIT_CONFIRM_WINDOW_MS)
  })
}
