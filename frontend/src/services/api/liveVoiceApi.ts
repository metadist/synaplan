import { z } from 'zod'
import {
  get_api_voice_live_options_Response,
  post_api_voice_live_sessions_end_Response,
  post_api_voice_live_sessions_start_Response,
  put_api_voice_live_consent_Response,
} from '@/generated/api-schemas'
import { ApiError, httpClient } from '@/services/api/httpClient'

export const liveVoiceOptionsSchema = get_api_voice_live_options_Response
export const liveVoiceSessionSchema = post_api_voice_live_sessions_start_Response

export type LiveVoiceOptions = z.infer<typeof liveVoiceOptionsSchema>
export type LiveVoiceSession = z.infer<typeof liveVoiceSessionSchema>
export type LiveVoiceModel = NonNullable<LiveVoiceOptions['models']>[number]

export type LiveVoiceCostBudget = {
  limit: string
  used: string
  remaining: string
  userLevel: string
  phoneVerified: boolean
  topupAvailable: boolean
}

export function liveCostBudget(value: LiveVoiceOptions['costBudget']): LiveVoiceCostBudget | null {
  if (!value) return null
  if (
    value.limit === undefined ||
    value.used === undefined ||
    value.remaining === undefined ||
    value.userLevel === undefined ||
    value.phoneVerified === undefined ||
    value.topupAvailable === undefined
  ) {
    return null
  }
  return {
    limit: value.limit,
    used: value.used,
    remaining: value.remaining,
    userLevel: value.userLevel,
    phoneVerified: value.phoneVerified,
    topupAvailable: value.topupAvailable,
  }
}

export function fetchLiveVoiceOptions(): Promise<LiveVoiceOptions> {
  return httpClient('/api/v1/voice/live/options', { schema: liveVoiceOptionsSchema })
}

export function startLiveVoiceSession(body: {
  chatId?: number | null
  modelId?: number | null
  voice?: string
  language?: string
  client: 'web'
  incognito?: boolean
  /** Incognito only. Omitted or true keeps saved memories and knowledge. */
  useSavedContext?: boolean
}): Promise<LiveVoiceSession> {
  return httpClient('/api/v1/voice/live/sessions', {
    method: 'POST',
    body: JSON.stringify(body),
    schema: liveVoiceSessionSchema,
  })
}

export function endLiveVoiceSession(
  sessionId: number,
  reason: 'user_end' | 'provider_error'
): Promise<unknown> {
  return httpClient(`/api/v1/voice/live/sessions/${sessionId}/end`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
    schema: post_api_voice_live_sessions_end_Response,
  })
}

export function setLiveVoiceConsent(given: boolean): Promise<{ given?: boolean }> {
  return httpClient('/api/v1/voice/live/consent', {
    method: 'PUT',
    body: JSON.stringify({ given }),
    schema: put_api_voice_live_consent_Response,
  })
}

export function isBudgetError(error: unknown): error is ApiError {
  return error instanceof ApiError && error.code === 'COST_BUDGET_EXCEEDED'
}
