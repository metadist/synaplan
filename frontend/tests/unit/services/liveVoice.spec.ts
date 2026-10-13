import { describe, expect, it } from 'vitest'
import { pcm16Frame } from '@/services/liveVoice/audio'
import { nextLivePhase } from '@/services/liveVoice/machine'
import {
  orbBreath,
  orbEnergy,
  orbLevel,
  orbScale,
  smoothOrbLevel,
  voiceLineAmplitude,
  voiceLineWave,
} from '@/services/liveVoice/orb'
import {
  liveEndReason,
  liveTerminalAction,
  liveTerminalActionKey,
  liveTerminalMessageKey,
} from '@/services/liveVoice/terminal'
import { liveVoiceSocketUrl } from '@/services/liveVoice/socket-url'
import { liveVoiceButtonVisible, hasNativeLiveVoice } from '@/services/liveVoice/visibility'

describe('live voice button', () => {
  it('shows the button only when the module is on for a signed-in web user', () => {
    expect(liveVoiceButtonVisible({ enabled: true, guest: false, nativeApp: false })).toBe(true)
    expect(liveVoiceButtonVisible({ enabled: false, guest: false, nativeApp: false })).toBe(false)
    expect(liveVoiceButtonVisible({ enabled: true, guest: true, nativeApp: false })).toBe(false)
    expect(liveVoiceButtonVisible({ enabled: true, guest: false, nativeApp: true })).toBe(false)
    expect(
      liveVoiceButtonVisible({
        enabled: true,
        guest: false,
        nativeApp: true,
        nativeLiveVoice: true,
      })
    ).toBe(true)
  })

  it('reads the binary capability and stays hidden when that binary omits it', () => {
    const global = globalThis as { SynaplanLiveVoice?: { isAvailable: () => boolean } }
    const previous = global.SynaplanLiveVoice
    delete global.SynaplanLiveVoice
    expect(hasNativeLiveVoice()).toBe(false)
    expect(liveVoiceButtonVisible({ enabled: true, guest: false, nativeApp: true })).toBe(false)

    global.SynaplanLiveVoice = { isAvailable: () => true }
    expect(hasNativeLiveVoice()).toBe(true)
    expect(liveVoiceButtonVisible({ enabled: true, guest: false, nativeApp: true })).toBe(true)

    global.SynaplanLiveVoice = {
      isAvailable: () => {
        throw new Error('bridge down')
      },
    }
    expect(hasNativeLiveVoice()).toBe(false)

    if (previous) global.SynaplanLiveVoice = previous
    else delete global.SynaplanLiveVoice
  })

  it('keeps the button when the monthly budget is already used up', () => {
    expect(liveVoiceButtonVisible({ enabled: true, guest: false, nativeApp: false })).toBe(true)
  })
})

describe('live voice socket', () => {
  it('dials the page origin and keeps the ticket on the path', () => {
    expect(
      liveVoiceSocketUrl('ws://backend/voice/ws?ticket=one', {
        protocol: 'http:',
        host: 'localhost:5173',
      })
    ).toBe('ws://localhost:5173/voice/ws?ticket=one')
  })
})

describe('live voice phases', () => {
  it('leaves connecting only for a real next state', () => {
    expect(nextLivePhase('connecting', { type: 'ready' })).toBe('listening')
    expect(nextLivePhase('listening', { type: 'audio' })).toBe('speaking')
    expect(nextLivePhase('speaking', { type: 'interrupted' })).toBe('listening')
    expect(nextLivePhase('listening', { type: 'wrap_up' })).toBe('ending')
    expect(nextLivePhase('connecting', { type: 'fail' })).toBe('ended')
    expect(nextLivePhase('ending', { type: 'audio' })).toBe('ending')
    expect(nextLivePhase('speaking', { type: 'audio_idle' })).toBe('listening')
    expect(nextLivePhase('ending', { type: 'audio_idle' })).toBe('ending')
  })

  it('sends microphone samples as little-endian PCM16', () => {
    expect(Array.from(pcm16Frame(new Int16Array([0x1234, -2])))).toEqual([0x34, 0x12, 0xfe, 0xff])
  })
})

describe('live voice orb', () => {
  it('follows the microphone while listening and the reply while speaking', () => {
    const levels = { micLevel: 0.6, outLevel: 0.3, muted: false }
    expect(orbLevel({ phase: 'listening', ...levels })).toBe(0.6)
    expect(orbLevel({ phase: 'speaking', ...levels })).toBe(0.3)
    expect(orbLevel({ phase: 'thinking', ...levels })).toBe(0)
    expect(orbLevel({ phase: 'connecting', ...levels })).toBe(0)
    expect(orbLevel({ phase: 'listening', ...levels, muted: true })).toBe(0)
  })

  it('grows with the level and rests when the conversation is over', () => {
    expect(orbScale('listening', 0)).toBe(1)
    expect(orbScale('speaking', 1)).toBeCloseTo(1.32)
    expect(orbScale('speaking', 4)).toBeCloseTo(1.32)
    expect(orbScale('ended', 1)).toBeLessThan(1)
    expect(orbBreath('ended', 1)).toBe(0)
  })

  it('makes quiet speech visible', () => {
    expect(orbEnergy(0.16)).toBeCloseTo(0.4)
    expect(orbEnergy(-1)).toBe(0)
  })

  it('swings the voice lines with the voice and lays them still when muted', () => {
    const quiet = voiceLineAmplitude({ phase: 'speaking', energy: 0, muted: false })
    const loud = voiceLineAmplitude({ phase: 'speaking', energy: 1, muted: false })
    expect(loud).toBe(1)
    expect(quiet).toBeGreaterThan(0)
    expect(quiet).toBeLessThan(0.2)
    expect(voiceLineAmplitude({ phase: 'listening', energy: 1, muted: true })).toBe(0)
    expect(voiceLineAmplitude({ phase: 'ended', energy: 1, muted: false })).toBe(0)
    expect(voiceLineAmplitude({ phase: 'thinking', energy: 0, muted: false })).toBeGreaterThan(0)
  })

  it('gives each voice line its own shape within one unit', () => {
    const samples = Array.from({ length: 64 }, (_, i) => (i / 64) * Math.PI * 2)
    const first = samples.map((angle) => voiceLineWave(angle, 1.5, 0))
    const second = samples.map((angle) => voiceLineWave(angle, 1.5, 1))
    expect([...first, ...second].every((v) => v >= -1 && v <= 1)).toBe(true)
    expect(first.some((v, i) => Math.abs(v - second[i]) > 0.3)).toBe(true)
  })

  it('rises faster than it falls', () => {
    const up = smoothOrbLevel(0, 1)
    const down = 1 - smoothOrbLevel(1, 0)
    expect(up).toBeGreaterThan(down)
    expect(smoothOrbLevel(0.5, Number.NaN)).toBeLessThan(0.5)
  })
})

describe('live voice terminal copy', () => {
  it('maps each ending to one sentence and one action', () => {
    expect(liveEndReason('nope')).toBe('provider_error')
    expect(liveTerminalMessageKey('network_lost')).toBe('chatInput.liveVoice.terminal.network_lost')
    expect(liveTerminalAction('limit_budget')).toBe('top_up')
    expect(liveTerminalAction('user_end')).toBe('close')
    expect(liveTerminalAction('mic_denied')).toBe('microphone')
    expect(liveTerminalAction('idle')).toBe('talk_again')
    expect(liveTerminalActionKey('microphone')).toBe(
      'chatInput.liveVoice.terminal.actionMicrophone'
    )
  })
})
