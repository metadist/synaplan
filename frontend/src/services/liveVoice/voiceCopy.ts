type Translate = (key: string) => string
type KeyExists = (key: string) => boolean

/** Shown when a roster id has no translated name. Mixed-case ids are already names. */
export function fallbackVoiceName(id: string): string {
  if (id === '' || id !== id.toLowerCase()) return id
  return id.charAt(0).toUpperCase() + id.slice(1)
}

export function voiceCopy(
  t: Translate,
  te: KeyExists,
  provider: string,
  id: string
): { name: string; tone: string } {
  const nameKey = `settings.voice.roster.${provider}.${id}.name`
  const toneKey = `settings.voice.roster.${provider}.${id}.tone`
  return {
    name: te(nameKey) ? t(nameKey) : fallbackVoiceName(id),
    tone: te(toneKey) ? t(toneKey) : '',
  }
}
