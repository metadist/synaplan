/**
 * Visible answer for surfaces that do not render a reasoning panel.
 *
 * Matches AiResponseSanitizer::stripForDisplay and the embedded widget:
 * a closed `<think>…</think>` block goes, and so does an unclosed trailing
 * `<think>`. The stored chat message is left unchanged.
 */
export const stripThinkingBlocks = (text: string): string => {
  let result = text.replace(/<think>[\s\S]*?<\/think>/gi, '')
  result = result.replace(/<think>[\s\S]*$/gi, '')
  return result.trim()
}

/** First `limit` characters of the visible answer. The cut is after the scratchpad. */
export const visiblePreview = (text: string | null | undefined, limit = 100): string => {
  if (!text) return ''
  const visible = stripThinkingBlocks(text)
  return visible.length <= limit ? visible : visible.slice(0, limit)
}
