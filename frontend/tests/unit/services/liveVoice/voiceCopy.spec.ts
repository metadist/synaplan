import { describe, expect, it } from 'vitest'
import en from '@/i18n/locales/en/settings.json'
import { fallbackVoiceName, voiceCopy } from '@/services/liveVoice/voiceCopy'

type VoiceEntry = { name: string; tone?: string }
type Roster = Record<string, Record<string, VoiceEntry>>

const roster = en.settings.voice.roster as Roster

describe('voice copy', () => {
  it('uses the translation when the roster has one', () => {
    const copy = voiceCopy(
      (key) => `tr:${key}`,
      (key) => key.endsWith('.name') || key.endsWith('.tone'),
      'google',
      'Puck'
    )
    expect(copy).toEqual({
      name: 'tr:settings.voice.roster.google.Puck.name',
      tone: 'tr:settings.voice.roster.google.Puck.tone',
    })
  })

  it('falls back to a readable name when the translation is missing', () => {
    const missing = () => false
    expect(voiceCopy(() => '', missing, 'openai', 'marin')).toEqual({
      name: 'Marin',
      tone: '',
    })
    expect(voiceCopy(() => '', missing, 'google', 'Puck').name).toBe('Puck')
    expect(fallbackVoiceName('cedar')).toBe('Cedar')
  })

  it('names every built-in live voice and keeps tones where the docs give one', () => {
    expect(Object.keys(roster.openai)).toHaveLength(22)
    expect(Object.keys(roster.google)).toHaveLength(30)
    expect(Object.keys(roster.xai)).toHaveLength(28)

    expect(roster.openai.marin).toMatchObject({ name: 'Marin' })
    expect(roster.openai.marin.tone).toBeTruthy()
    expect(roster.openai.cedar.tone).toBeTruthy()
    expect(roster.openai.quartz.tone).toBeTruthy()
    expect(roster.openai.alloy.tone).toBeUndefined()

    expect(roster.google.Puck.name).toBe('Puck')
    expect(roster.google.Aoede.tone).toBeTruthy()
    for (const voice of Object.values(roster.google)) {
      expect(voice.tone).toBeTruthy()
    }

    expect(roster.xai.eve.name).toBe('Eve')
    expect(roster.xai.carina.tone).toBeTruthy()
    expect(roster.xai.liora.tone).toBeTruthy()
    for (const voice of Object.values(roster.xai)) {
      expect(voice.tone).toBeTruthy()
    }
  })
})
