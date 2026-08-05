/**
 * Formats the running Synaplan release for user-facing surfaces (sidebar, titles).
 *
 * Mutable image tags such as `latest` must never appear as the version label —
 * they are registry pointers, not releases. A missing or unknown value hides the
 * label rather than showing a placeholder.
 */
export const formatRunningVersion = (version: string | null | undefined): string => {
  if (!version) return ''

  const trimmed = version.trim()
  if (!trimmed || trimmed === 'unknown' || trimmed === 'latest') return ''

  return /^\d/.test(trimmed) ? `v${trimmed}` : trimmed
}
