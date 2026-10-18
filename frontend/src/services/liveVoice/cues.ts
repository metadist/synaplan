import type { LivePhase } from './machine'

/** Short tones for the moments a person needs to hear, not every flicker of the call. */
export type LiveCue = 'open' | 'ready' | 'ask' | 'end' | 'fail'

export interface LiveCueStep {
  frequency: number
  toFrequency?: number
  durationMs: number
  gain: number
}

const FAILURES = new Set([
  'provider_error',
  'network_lost',
  'capacity',
  'busy',
  'mic_denied',
  'admin_disabled',
])

const ASK_GAP_MS = 900

let lastAskAt = 0
let cueContext: AudioContext | null = null

/**
 * One tone per moment: the call opening, the line becoming ready, a question
 * going to Synaplan, the hang-up, and a failed start. Speaking and listening
 * stay quiet because the voices are already the sound.
 */
export function liveCueForTransition(
  previous: LivePhase | undefined,
  next: LivePhase,
  reason = ''
): LiveCue | null {
  if (previous === undefined || previous === next) return null
  if (next === 'connecting' && (previous === 'idle' || previous === 'ended')) return 'open'
  if (next === 'listening' && previous === 'connecting') return 'ready'
  if (next === 'thinking') return 'ask'
  if (next === 'idle' && previous !== 'ended') return 'end'
  if (next === 'ended') return FAILURES.has(reason) ? 'fail' : 'end'
  return null
}

/** Soft sine steps in a narrow, quiet range. Rising means start, falling means stop. */
export function liveCueSteps(cue: LiveCue): LiveCueStep[] {
  if (cue === 'open') {
    return [
      { frequency: 392, durationMs: 80, gain: 0.045 },
      { frequency: 523.25, durationMs: 130, gain: 0.05 },
    ]
  }
  if (cue === 'ready') {
    return [
      { frequency: 523.25, durationMs: 70, gain: 0.05 },
      { frequency: 659.25, durationMs: 150, gain: 0.055 },
    ]
  }
  if (cue === 'ask') {
    return [{ frequency: 440, toFrequency: 659.25, durationMs: 170, gain: 0.04 }]
  }
  if (cue === 'fail') {
    return [
      { frequency: 311, durationMs: 90, gain: 0.035 },
      { frequency: 247, durationMs: 150, gain: 0.03 },
    ]
  }
  return [
    { frequency: 659.25, durationMs: 70, gain: 0.04 },
    { frequency: 392, durationMs: 170, gain: 0.04 },
  ]
}

function motionReduced(): boolean {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true
}

function cueAudio(): AudioContext | null {
  const Context =
    window.AudioContext ??
    (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Context) return null
  if (!cueContext || cueContext.state === 'closed') cueContext = new Context()
  if (typeof cueContext.createOscillator !== 'function') return null
  void cueContext.resume().catch(() => undefined)
  return cueContext
}

/** Plays a cue. A repeated hand-off inside one second stays a single tone. */
export function playLiveCue(cue: LiveCue, now = Date.now()): void {
  if (motionReduced()) return
  if (cue === 'ask' && now - lastAskAt < ASK_GAP_MS) return
  if (cue === 'ask') lastAskAt = now
  const ctx = cueAudio()
  if (!ctx) return
  let at = ctx.currentTime + 0.02
  for (const step of liveCueSteps(cue)) {
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    const seconds = step.durationMs / 1000
    osc.type = 'sine'
    osc.frequency.setValueAtTime(step.frequency, at)
    if (step.toFrequency && step.toFrequency > 0) {
      osc.frequency.exponentialRampToValueAtTime(step.toFrequency, at + seconds)
    }
    gain.gain.setValueAtTime(0.0001, at)
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, step.gain), at + 0.018)
    gain.gain.exponentialRampToValueAtTime(0.0001, at + seconds)
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.start(at)
    osc.stop(at + seconds + 0.02)
    osc.onended = () => {
      osc.disconnect()
      gain.disconnect()
    }
    at += seconds + 0.045
  }
}
