import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useFreeApiStore } from '@/stores/freeApi'

/** Long enough to read the sentence and reach Undo. */
const UNDO_TOAST_MS = 12000

/**
 * Saving an own chat provider turns the Synaplan Demo off on the server
 * (`demoLeft` in the save response). This replaces the surface's "saved"
 * toast with one that says so and offers Undo, which starts the Demo again
 * even though an own provider now exists.
 */
export function useFreeApiAutoLeave() {
  const { t, locale } = useI18n()
  const { push, success, error } = useNotification()
  const freeApi = useFreeApiStore()

  async function undo(provider: string): Promise<void> {
    try {
      await freeApi.start(locale.value, true)
      success(t('freeApi.autoLeave.undone'))
    } catch {
      error(t('freeApi.autoLeave.undoFailed', { provider }))
    }
  }

  /**
   * Returns true when the Demo was left and the toast was shown; the caller
   * then skips its own success toast.
   *
   * `undo: false` is for an own OpenAI-compatible endpoint: the server never
   * starts the Demo beside one, so an Undo there could only fail.
   */
  async function announce(
    result: { demoLeft?: boolean; defaultsApplied?: boolean },
    provider: string,
    options: { undo?: boolean } = {}
  ): Promise<boolean> {
    if (result.demoLeft !== true) return false
    await freeApi.syncAfterAutoLeave()
    if (options.undo === false) {
      push({
        type: 'success',
        message: t('freeApi.autoLeave.endpointLeft', { provider }),
        duration: UNDO_TOAST_MS,
      })
      return true
    }
    push({
      type: 'success',
      message: result.defaultsApplied
        ? t('freeApi.autoLeave.switched', { provider })
        : t('freeApi.autoLeave.left', { provider }),
      duration: UNDO_TOAST_MS,
      action: { label: t('freeApi.autoLeave.undo'), onClick: () => void undo(provider) },
    })
    return true
  }

  return { announce }
}
