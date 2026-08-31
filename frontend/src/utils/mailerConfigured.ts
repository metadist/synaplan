import { getConfigSync } from '@/services/api/httpClient'

/**
 * Whether this installation can send mail.
 *
 * Lives here instead of `stores/config.ts` so a missing-mailer hint on
 * /forgot-password stays OTA-deliverable. Defaults to true: an older backend
 * without the flag must not flash a CLI recovery command on a hosted instance
 * that does deliver mail.
 */
export function isMailerConfigured(): boolean {
  return false !== getConfigSync().auth?.mailerConfigured
}
