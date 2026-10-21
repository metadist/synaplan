import { computed, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useFreeApiStore } from '@/stores/freeApi'
import { freeApiStartErrorKey } from '@/utils/freeApiCopy'

/**
 * A fresh install without an own AI provider opens straight into chat. The
 * Synaplan Demo starts with the admin's first message, not before: until then
 * nothing leaves this server, and the sentence above the composer says where
 * that message will go.
 */
export function useFreeApiStartOnSend(
  chatHasNoProvider: Ref<boolean>,
  restoreDraft: (text: string) => void
) {
  const { locale } = useI18n()
  const authStore = useAuthStore()
  const freeApi = useFreeApiStore()

  const startErrorKey = ref('')

  // `busy` keeps the composer mounted while the start reloads the runtime
  // config: the status turns active before chat readiness does.
  const startsOnSend = computed(
    () =>
      chatHasNoProvider.value &&
      authStore.isAdmin &&
      freeApi.isAvailable() &&
      (freeApi.status?.active !== true || freeApi.busy)
  )

  /**
   * False when the Demo had to start and did not: the message stays unsent,
   * goes back into the composer, and `startErrorKey` says why.
   */
  async function readyForSend(content: string): Promise<boolean> {
    if (!startsOnSend.value) return true
    startErrorKey.value = ''
    try {
      await freeApi.start(locale.value)
      return true
    } catch (err) {
      startErrorKey.value = freeApiStartErrorKey(err)
      restoreDraft(content)
      return false
    }
  }

  return { startsOnSend, startErrorKey, readyForSend }
}
