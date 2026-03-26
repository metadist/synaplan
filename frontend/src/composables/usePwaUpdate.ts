import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from './useNotification'
import { useDialog } from './useDialog'

export const usePwaUpdate = () => {
  const { t } = useI18n()
  const { info, warning } = useNotification()
  const { confirm } = useDialog()
  const isOnline = ref(navigator.onLine)

  const { offlineReady, needRefresh, updateServiceWorker } = useRegisterSW({
    immediate: true,
    onRegisteredSW(
      _swUrl: string,
      registration: ServiceWorkerRegistration | undefined,
    ) {
      if (!registration) return

      const intervalMs = 60 * 60 * 1000
      setInterval(() => {
        registration.update()
      }, intervalMs)
    },
  })

  watch(offlineReady, (ready) => {
    if (ready) {
      info(t('pwa.offlineReady'))
    }
  })

  watch(needRefresh, async (refresh) => {
    if (!refresh) return

    const accepted = await confirm({
      title: t('pwa.updateAvailable'),
      message: t('pwa.updateMessage'),
      confirmText: t('pwa.updateNow'),
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
    warning(t('pwa.offline'), 0)
  }

  onMounted(() => {
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
  })

  onUnmounted(() => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  })

  return {
    isOnline,
    offlineReady,
    needRefresh,
  }
}
