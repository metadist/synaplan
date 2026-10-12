import { describe, expect, it } from 'vitest'
import { pcm16Frame } from '@/services/liveVoice/audio'
import { nextLivePhase } from '@/services/liveVoice/machine'
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
