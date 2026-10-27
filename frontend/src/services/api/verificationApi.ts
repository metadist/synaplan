import type { z } from 'zod'
import { PostApiAuthResendVerificationResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ResendVerificationResult = z.infer<typeof PostApiAuthResendVerificationResponseSchema>

/**
 * Kept out of `authApi.ts` so a change to the resend flow stays OTA-deliverable:
 * every `auth`-named file is classified store-required.
 */
export function resendVerificationEmail(email: string): Promise<ResendVerificationResult> {
  return httpClient('/api/v1/auth/resend-verification', {
    method: 'POST',
    body: JSON.stringify({ email }),
    skipAuth: true,
    schema: PostApiAuthResendVerificationResponseSchema,
  })
}
