/** Levels that mean "do not reason". `low` is a real level, not off. */
const SKIP_LEVELS = new Set(['none', 'minimal'])

export function modelReasoningLevels(
  model: { reasoningLevels?: string[] } | null | undefined
): string[] {
  const levels = model?.reasoningLevels
  if (!levels || levels.length === 0) {
    return []
  }

  return levels.filter((level) => level.length > 0)
}

export function initialReasoningLevel(
  levels: string[],
  preferred: string | null | undefined
): string {
  if (preferred && levels.includes(preferred)) {
    return preferred
  }

  return levels[0] ?? ''
}

export function reasoningSendFlags(
  levels: string[],
  chosen: string,
  thinkingEnabled: boolean
): { includeReasoning: boolean; reasoningEffort?: string } {
  if (levels.includes(chosen)) {
    return {
      includeReasoning: !SKIP_LEVELS.has(chosen),
      reasoningEffort: chosen,
    }
  }

  return { includeReasoning: thinkingEnabled }
}
