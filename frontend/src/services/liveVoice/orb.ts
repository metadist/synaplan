import type { LivePhase } from './machine'

export interface LiveVoiceStageState {
  phase: LivePhase
  status: string
  statusHint: string
  micLevel: number
  outLevel: number
  muted: boolean
  captionText: string
  showCaption: boolean
}

const REST_SCALE = 0.9
const GROWTH = 0.32
const LINE_FLOOR = 0.08

function clamp(value: number): number {
  if (!Number.isFinite(value)) return 0
  return Math.min(1, Math.max(0, value))
}

function isResting(phase: LivePhase): boolean {
  return phase === 'idle' || phase === 'ended'
}

/**
 * The level the orb follows: the microphone while the person talks, the
 * reply while the assistant talks. Thinking and connecting keep their own
 * rhythm, so a level left over from the last turn cannot make them pulse.
 */
export function orbLevel(input: {
  phase: LivePhase
  micLevel: number
  outLevel: number
  muted: boolean
}): number {
  if (input.phase === 'listening') return input.muted ? 0 : clamp(input.micLevel)
  if (input.phase === 'speaking' || input.phase === 'ending') return clamp(input.outLevel)
  return 0
}

/** Speech sits low on the RMS scale; the square root makes quiet words visible. */
export function orbEnergy(level: number): number {
  return Math.sqrt(clamp(level))
}

export function orbScale(phase: LivePhase, energy: number): number {
  if (isResting(phase)) return REST_SCALE
  return 1 + GROWTH * clamp(energy)
}

/** A slow breath on top of the voice, faster while connecting or thinking. */
export function orbBreath(phase: LivePhase, seconds: number): number {
  if (isResting(phase)) return 0
  if (phase === 'connecting') return 0.035 * Math.sin(seconds * 2.6)
  if (phase === 'thinking') return 0.03 * Math.sin(seconds * 3.4)
  return 0.015 * Math.sin(seconds * 1.4)
}

/** Rises fast and falls slowly, so speech reads as a swell instead of a flicker. */
export function smoothOrbLevel(previous: number, target: number): number {
  const factor = target > previous ? 0.35 : 0.08
  return previous + (clamp(target) - previous) * factor
}

/**
 * How far the voice lines around the orb swing, from 0 to 1. They follow the
 * voice while someone talks, keep a fixed swirl while thinking, ripple softly
 * while connecting, and lie as calm circles when muted or finished.
 */
export function voiceLineAmplitude(input: {
  phase: LivePhase
  energy: number
  muted: boolean
}): number {
  const { phase } = input
  if (isResting(phase) || (phase === 'listening' && input.muted)) return 0
  if (phase === 'connecting') return 0.14
  if (phase === 'thinking') return 0.32
  return LINE_FLOOR + (1 - LINE_FLOOR) * clamp(input.energy)
}

/**
 * The shape of one voice line at an angle, from -1 to 1. Each line mixes
 * three slow waves with its own phase, so the lines cross and part like
 * strands instead of moving as one ring.
 */
export function voiceLineWave(angle: number, seconds: number, line: number): number {
  const offset = line * 2.1
  return (
    0.5 * Math.sin(angle * 3 + seconds * 1.7 + offset) +
    0.3 * Math.sin(angle * 5 - seconds * 2.3 + offset * 0.6) +
    0.2 * Math.sin(angle * 2 + seconds * 0.9 - offset)
  )
}
