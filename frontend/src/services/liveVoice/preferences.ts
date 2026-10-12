const CAPTIONS_KEY = 'synaplan.liveVoice.captions'
const VOICES_KEY = 'synaplan.liveVoice.voices'

export function captionsPreferred(): boolean {
  try {
    return localStorage.getItem(CAPTIONS_KEY) !== '0'
  } catch {
    return true
  }
}

export function setCaptionsPreferred(on: boolean): void {
  try {
    localStorage.setItem(CAPTIONS_KEY, on ? '1' : '0')
  } catch {
    // A private window can refuse storage. The toggle still works for this visit.
  }
}

export function preferredVoice(provider: string): string {
  try {
    const raw = localStorage.getItem(VOICES_KEY)
    if (!raw) return ''
    const parsed = JSON.parse(raw) as Record<string, unknown>
    const voice = parsed[provider]
    return typeof voice === 'string' ? voice : ''
  } catch {
    return ''
  }
}

export function setPreferredVoice(provider: string, voice: string): void {
  try {
    const raw = localStorage.getItem(VOICES_KEY)
    const parsed = raw ? (JSON.parse(raw) as Record<string, unknown>) : {}
    parsed[provider] = voice
    localStorage.setItem(VOICES_KEY, JSON.stringify(parsed))
  } catch {
    // Same as captions: the choice still applies to the conversation being started.
  }
}
