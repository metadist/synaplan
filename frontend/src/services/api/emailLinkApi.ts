import {
  ConsumeEmailSignInLinkResponseSchema,
  RequestEmailSignInLinkResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export const requestEmailSignInLink = (email: string) =>
  httpClient('/api/v1/auth/email-link', {
    method: 'POST',
    body: JSON.stringify({ email }),
    schema: RequestEmailSignInLinkResponseSchema,
    skipAuth: true,
  })

export const consumeEmailSignInLink = (token: string) =>
  httpClient('/api/v1/auth/email-link/consume', {
    method: 'POST',
    body: JSON.stringify({ token }),
    schema: ConsumeEmailSignInLinkResponseSchema,
    skipAuth: true,
  })
