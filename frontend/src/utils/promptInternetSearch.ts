import type { PromptMetadata } from '@/services/api/promptsApi'

/**
 * Web-search setting for a prompt.
 *
 * The backend's `tool_internet` metadata flag is read by the routing layer
 * (`WebSearchTopicPolicy::shouldSearch`):
 *   - 'auto' → key absent (or a stored `true`) → the AI decides per message
 *   - 'off'  → `false`                         → never search
 *
 * A prompt cannot force a search on every message. A stored `true` from an
 * older build reads as 'auto' and is dropped on the next save.
 */
export type InternetSearchMode = 'auto' | 'off'

/**
 * Derive the mode from prompt metadata.
 *
 * Only an explicit `false` is 'off'. The legacy `tool_internet_search` alias
 * is honoured for older metadata rows.
 */
export function internetModeFromMetadata(
  metadata: PromptMetadata | null | undefined
): InternetSearchMode {
  const raw =
    metadata?.tool_internet ?? (metadata?.tool_internet_search as boolean | null | undefined)
  return false === raw ? 'off' : 'auto'
}

/**
 * Write the mode back into a metadata payload.
 *
 * 'off' sets `tool_internet=false`. For 'auto' the key is left unset (the
 * metadata save path rewrites the whole set, so an absent key clears any
 * previously stored value).
 */
export function applyInternetModeToMetadata(
  metadata: PromptMetadata,
  mode: InternetSearchMode
): void {
  if ('off' === mode) {
    metadata.tool_internet = false
  } else {
    delete metadata.tool_internet
  }
  delete metadata.tool_internet_search
}
