export type LiveEndReason =
  | 'user_end'
  | 'idle'
  | 'max_duration'
  | 'limit_budget'
  | 'provider_error'
  | 'network_lost'
  | 'capacity'
  | 'busy'
  | 'mic_denied'
  | 'admin_disabled'

const REASONS: readonly LiveEndReason[] = [
  'user_end',
  'idle',
  'max_duration',
  'limit_budget',
  'provider_error',
  'network_lost',
  'capacity',
  'busy',
  'mic_denied',
  'admin_disabled',
]

export type LiveTerminalAction = 'close' | 'talk_again' | 'microphone' | 'top_up'

export function liveEndReason(value: string | null | undefined): LiveEndReason {
  if (value && (REASONS as readonly string[]).includes(value)) return value as LiveEndReason
  return 'provider_error'
}

export function liveTerminalAction(reason: LiveEndReason): LiveTerminalAction {
  if (reason === 'limit_budget') return 'top_up'
  if (reason === 'user_end') return 'close'
  // A blocked microphone or a switched-off feature cannot be retried from here.
  if (reason === 'mic_denied' || reason === 'admin_disabled') return 'close'
  if (reason === 'provider_error' || reason === 'capacity') return 'microphone'
  return 'talk_again'
}

export function liveTerminalMessageKey(reason: LiveEndReason): string {
  return `chatInput.liveVoice.terminal.${reason}`
}

export function liveTerminalActionKey(action: LiveTerminalAction): string {
  if (action === 'top_up') return 'chatInput.liveVoice.terminal.actionTopUp'
  if (action === 'microphone') return 'chatInput.liveVoice.terminal.actionMicrophone'
  if (action === 'talk_again') return 'chatInput.liveVoice.terminal.actionTalkAgain'
  return 'chatInput.liveVoice.terminal.actionClose'
}

export function liveErrorReason(error: unknown): LiveEndReason {
  if (
    error instanceof DOMException &&
    (error.name === 'NotAllowedError' || error.name === 'NotFoundError')
  ) {
    return 'mic_denied'
  }
  const api = error as { name?: string; status?: number; code?: string } | null
  if (api && typeof api === 'object' && api.name === 'ApiError') {
    if (api.status === 409 && api.code === 'VOICE_BUSY') return 'busy'
    if (api.status === 503) return 'capacity'
  }
  return 'provider_error'
}
