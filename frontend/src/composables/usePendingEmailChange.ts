import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { getErrorMessage } from '@/utils/errorMessage'
import { cancelEmailChange as cancelPendingEmailChange } from '@/services/api/emailChangeApi'

/**
 * A new sign-in email is not stored when the profile is saved. It waits,
 * shown as pending, until the link mailed to it is opened, and it can be
 * cancelled until then.
 */
export function usePendingEmailChange() {
  const { error, success } = useNotification()
  const { t } = useI18n()

  const emailPassword = ref('')
  const pendingEmail = ref<string | null>(null)

  /** Call after a save that asked for a new address; returns what to tell the user. */
  const saved = (pending: string | null | undefined, current: string): string => {
    emailPassword.value = ''
    pendingEmail.value = pending ?? null
    return pendingEmail.value
      ? t('profile.personalInfo.emailLinkSent', { email: pendingEmail.value, current })
      : t('unsavedChanges.saved')
  }

  const cancelEmailChange = async () => {
    try {
      await cancelPendingEmailChange()
      pendingEmail.value = null
      success(t('profile.personalInfo.emailPendingCancelled'))
    } catch (err: unknown) {
      error(getErrorMessage(err) || t('profile.saveFailed'))
    }
  }

  return { emailPassword, pendingEmail, saved, cancelEmailChange }
}
