import {
  ConsumeCodeSignupResponseSchema,
  RequestCodeSignupResponseSchema,
} from '@/generated/api-schemas'
import type { z } from 'zod'
import { httpClient } from './httpClient'

export type CodeSignupRequest = z.infer<typeof RequestCodeSignupResponseSchema>
export type CodeSignupSession = z.infer<typeof ConsumeCodeSignupResponseSchema>

export const requestCodeSignup = (
  email: string,
  code: string,
  challengeToken = ''
): Promise<CodeSignupRequest> =>
  httpClient('/api/v1/auth/code-signup', {
    method: 'POST',
    body: JSON.stringify({ email, code, challengeToken }),
    schema: RequestCodeSignupResponseSchema,
    skipAuth: true,
  })

export const consumeCodeSignup = (token: string): Promise<CodeSignupSession> =>
  httpClient('/api/v1/auth/code-signup/consume', {
    method: 'POST',
    body: JSON.stringify({ token }),
    schema: ConsumeCodeSignupResponseSchema,
    skipAuth: true,
  })
