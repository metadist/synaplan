export type EmptyLandingActionsInput = {
  incognito: boolean
  hasPinnedAssistant: boolean
  starterPromptCount: number
  canCompose: boolean
  needsProviderSetup: boolean
}

/**
 * Companion product cards belong on the unpinned start page only.
 * A pinned assistant owns that empty state — even when it has no starters.
 */
export function shouldShowCompanionLinks(input: EmptyLandingActionsInput): boolean {
  return !input.incognito && !input.hasPinnedAssistant
}

/**
 * "What can you do" is the fallback action when the composer can submit
 * and the assistant (if any) did not bring its own starter prompts.
 */
export function shouldShowSelfAwareEmptyHint(input: EmptyLandingActionsInput): boolean {
  if (input.incognito || !input.canCompose || input.needsProviderSetup) {
    return false
  }
  if (input.hasPinnedAssistant && input.starterPromptCount > 0) {
    return false
  }
  return true
}
