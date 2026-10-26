import { RequestEmailSignInLinkResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export const requestEmailSignInLink = (email: string) =>
  httpClient('/api/v1/auth/email-link', {
    method: 'POST',
    body: JSON.stringify({ email }),
    schema: RequestEmailSignInLinkResponseSchema,
    skipAuth: true,
  })
