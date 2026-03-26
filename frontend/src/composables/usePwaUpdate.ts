import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from './useNotification'
import { useDialog } from './useDialog'

const SW_UPDATE_INTERVAL_MS = 60 * 60 * 1000

export const usePwaUpdate = () => {
  const { t } = useI18n()
  const { info, warning } = useNotification()
  const { confirm } = useDialog()
  const isOnline = ref(navigator.onLine)
  let updateInterval: ReturnType<typeof setInterval> | undefined

  const { offlineReady, needRefresh, updateServiceWorker } = useRegisterSW({
    immediate: true,
    onRegisteredSW(_swUrl: string, registration: ServiceWorkerRegistration | undefined) {
      if (!registration) return

      updateInterval = setInterval(() => {
        registration.update()
      }, SW_UPDATE_INTERVAL_MS)
    },
  })

  watch(offlineReady, (ready) => {
    if (ready) {
      info(t('pwa.offlineReady'))
    }
  })

  watch(needRefresh, async (refresh) => {
    if (!refresh) return

    if (navigator.webdriver) {
      await updateServiceWorker(true)
      return
    }

    const accepted = await confirm({
      title: t('pwa.updateAvailable'),
      message: t('pwa.updateMessage'),
      confirmText: t('pwa.updateNow'),
      cancelText: t('common.cancel'),
    })

    if (accepted) {
      await updateServiceWorker(true)
    }
  })

  const onOnline = () => {
    isOnline.value = true
    info(t('pwa.online'))
  }

  const onOffline = () => {
    isOnline.value = false
    warning(t('pwa.offline'))
  }

  onMounted(() => {
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
  })

  onUnmounted(() => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    if (updateInterval) {
      clearInterval(updateInterval)
    }
  })

  return {
    isOnline,
    offlineReady,
    needRefresh,
  }
}
