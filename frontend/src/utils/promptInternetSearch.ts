import type { PromptMetadata } from '@/services/api/promptsApi'

/**
 * Tri-state web-search setting for a prompt (issue #1138).
 *
 * The backend's `tool_internet` metadata flag has three meaningful states and
 * the routing layer (`WebSearchTopicPolicy::shouldSearch`) treats them
 * differently:
 *   - 'auto' → key absent / null → no preference, the classifier decides
 *   - 'on'   → `true`            → always search
 *   - 'off'  → `false`           → never search
 *
 * A plain checkbox can only express two states and silently collapsed the
 * 'auto' default into 'off' on save, hard-disabling web search.
 */
export type InternetSearchMode = 'auto' | 'on' | 'off'

/**
 * Derive the tri-state mode from prompt metadata.
 *
 * Uses `??` so an explicit `false` ('off') is preserved instead of being
 * collapsed into 'auto'; only a missing/null value falls through to 'auto'.
 * The legacy `tool_internet_search` alias is honoured for older metadata rows.
 */
export function internetModeFromMetadata(
  metadata: PromptMetadata | null | undefined
): InternetSearchMode {
  const raw =
    metadata?.tool_internet ?? (metadata?.tool_internet_search as boolean | null | undefined)
  if (true === raw) {
    return 'on'
  }
  if (false === raw) {
    return 'off'
  }
  return 'auto'
}

/**
 * Write the tri-state mode back into a metadata payload.
 *
 * Only the explicit 'on'/'off' choices set `tool_internet`. For 'auto' the key
 * is left unset so the backend keeps the "classifier decides" default (the
 * metadata save path rewrites the whole set, so an absent key clears any
 * previously stored override).
 */
export function applyInternetModeToMetadata(
  metadata: PromptMetadata,
  mode: InternetSearchMode
): void {
  if ('on' === mode) {
    metadata.tool_internet = true
  } else if ('off' === mode) {
    metadata.tool_internet = false
  } else {
    delete metadata.tool_internet
  }
}
