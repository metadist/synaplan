/**
 * Synaplan Demo API: everyone signed in reads the state, admins start and leave it.
 * All three answer 404 `feature_not_configured` while the `freeapi` module is off.
 */
import type { z } from 'zod'
import {
  GetApiFreeapiStatusResponseSchema,
  PostApiFreeapiActivateResponseSchema,
  PostApiFreeapiLeaveResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type FreeApiStatus = z.infer<typeof GetApiFreeapiStatusResponseSchema>
export type FreeApiLeaveResult = z.infer<typeof PostApiFreeapiLeaveResponseSchema>

export async function getFreeApiStatus(): Promise<FreeApiStatus> {
  return httpClient('/api/v1/freeapi/status', { schema: GetApiFreeapiStatusResponseSchema })
}

/**
 * `consent` is sent only from a surface that showed the consent sentence.
 * `force` starts even though an own provider exists (Undo after auto-leave).
 */
export async function activateFreeApi(options: {
  consent: true
  locale: string
  force?: boolean
}): Promise<FreeApiStatus> {
  return httpClient('/api/v1/admin/freeapi/activate', {
    method: 'POST',
    body: JSON.stringify(options),
    schema: PostApiFreeapiActivateResponseSchema,
  })
}

export async function leaveFreeApi(): Promise<FreeApiLeaveResult> {
  return httpClient('/api/v1/admin/freeapi/leave', {
    method: 'POST',
    schema: PostApiFreeapiLeaveResponseSchema,
  })
}
