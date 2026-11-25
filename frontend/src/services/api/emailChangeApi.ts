import {
  CancelEmailChangeResponseSchema,
  ConfirmEmailChangeResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export const confirmEmailChange = (token: string) =>
  httpClient('/api/v1/auth/email-change/confirm', {
    method: 'POST',
    body: JSON.stringify({ token }),
    schema: ConfirmEmailChangeResponseSchema,
    skipAuth: true,
  })

export const cancelEmailChange = () =>
  httpClient('/api/v1/profile/email-change', {
    method: 'DELETE',
    schema: CancelEmailChangeResponseSchema,
  })
