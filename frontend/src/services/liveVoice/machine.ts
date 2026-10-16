export type LivePhase =
  'idle' | 'connecting' | 'listening' | 'thinking' | 'speaking' | 'ending' | 'ended'

export type LiveEvent =
  | { type: 'start' }
  | { type: 'ready' }
  | { type: 'state'; state: 'thinking' | 'listening' | 'speaking' }
  | { type: 'audio' }
  | { type: 'audio_idle' }
  | { type: 'interrupted' }
  | { type: 'wrap_up' }
  | { type: 'ended' }
  | { type: 'fail' }

export function nextLivePhase(phase: LivePhase, event: LiveEvent): LivePhase {
  if (event.type === 'ended' || event.type === 'fail') return 'ended'
  if (event.type === 'wrap_up') return 'ending'
  if (phase === 'idle') return event.type === 'start' ? 'connecting' : 'idle'
  if (phase === 'ended') return event.type === 'start' ? 'connecting' : 'ended'
  if (event.type === 'start') return 'connecting'
  if (event.type === 'ready') return 'listening'
  if (event.type === 'state') {
    if (phase === 'ending') return 'ending'
    if (event.state === 'thinking') return 'thinking'
    if (event.state === 'speaking') return 'speaking'
    return 'listening'
  }
  if (event.type === 'audio') return phase === 'ending' ? 'ending' : 'speaking'
  if (event.type === 'audio_idle') return phase === 'speaking' ? 'listening' : phase
  if (event.type === 'interrupted') return phase === 'ending' ? 'ending' : 'listening'
  return phase
}
