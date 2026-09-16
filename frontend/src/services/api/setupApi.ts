/**
 * First-run setup wizard endpoints.
 *
 * Only reachable on an installation that has no administrator yet — everything
 * else in the API answers 503 SETUP_REQUIRED in that state, and these three
 * answer 409 once the installation has any user.
 */

import { httpClient } from './httpClient'
import { setNativeTokens } from '@/services/api/nativeAuth'
import { setSessionHint } from '@/services/sessionHint'
import { authService } from '@/services/authService'
import {
  GetApiSetupStateResponseSchema,
  PostApiSetupAdminResponseSchema,
  PostApiSetupCompleteResponseSchema,
} from '@/generated/api-schemas'
import type { z } from 'zod'

export type SetupState = z.infer<typeof GetApiSetupStateResponseSchema>
export type SetupAdminResult = z.infer<typeof PostApiSetupAdminResponseSchema>
export type SetupCompleteResult = z.infer<typeof PostApiSetupCompleteResponseSchema>

export const getSetupState = async (): Promise<SetupState> =>
  httpClient('/api/v1/setup/state', {
    schema: GetApiSetupStateResponseSchema,
    skipAuth: true,
  })

/**
 * Creates the administrator AND adopts the session the server opens for it.
 *
 * The adoption is not optional bookkeeping. Both auth paths gate themselves on a
 * client-side marker before they will even attempt a token refresh
 * (`hasSessionHint()` on web, `hasNativeTokens()` in the app), so without this
 * the wizard would hold a perfectly valid cookie that nothing is allowed to
 * renew: step 2 is where the administrator leaves to fetch an API key, and the
 * five-minute access token expires while they are gone. The next call 401s, the
 * refresh short-circuits, and the wizard drops them on the login page halfway
 * through. The native shell fares worse still — it authenticates by Bearer, so
 * discarding the tokens from the body strands it immediately after step 1.
 */
export const createFirstAdmin = async (
  email: string,
  password: string
): Promise<SetupAdminResult> => {
  const result = await httpClient('/api/v1/setup/admin', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
    schema: PostApiSetupAdminResponseSchema,
    skipAuth: true,
  })

  // Same order as a normal login: tokens first (the app needs them for the very
  // next request), then the hint that unlocks the refresh path, then the user
  // so Pinia is authenticated before /auth/me can run.
  setNativeTokens(result.tokens)
  setSessionHint()
  authService.adoptSession({
    id: result.user.id,
    email: result.user.email,
    level: result.user.level,
    isAdmin: result.user.isAdmin,
    emailVerified: true,
  })

  return result
}

/**
 * Closes the setup window. Authenticated as the administrator that
 * {@link createFirstAdmin} just signed in, so no `skipAuth` here.
 */
export const completeSetup = async (
  registrationEnabled: boolean,
  guestChatEnabled: boolean
): Promise<SetupCompleteResult> => {
  const result = await httpClient('/api/v1/setup/complete', {
    method: 'POST',
    body: JSON.stringify({ registrationEnabled, guestChatEnabled }),
    schema: PostApiSetupCompleteResponseSchema,
  })

  const { invalidateSetupWizardRequired } = await import('@/router/setupGate')
  invalidateSetupWizardRequired()

  return result
}
