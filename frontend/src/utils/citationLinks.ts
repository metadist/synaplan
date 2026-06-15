const SOURCE_REF_CLASSES =
  'source-ref inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-bold hover:bg-[var(--brand)] hover:text-white transition-all mx-0.5 no-underline'

/**
 * Replaces citation markers like [1], [1†source], [1↑source], [1‡source] in
 * AI-generated text with clickable badge anchors pointing to a source by index.
 *
 * OpenAI and other providers sometimes append a suffix such as `†source`,
 * `↑source`, or `‡source` inside the brackets. The suffix is stripped from the
 * visible badge; only the numeric index is displayed.
 *
 * @param content       Raw text content from the AI response.
 * @param sourceCount   Number of available search result sources.
 * @returns             Content with citation markers replaced by anchor markup.
 */
export function replaceCitationMarkers(content: string, sourceCount: number): string {
  return content.replace(/\[(\d+)(?:[†↑‡][^\]]*)?\]/g, (match, num: string) => {
    const index = parseInt(num) - 1
    if (index >= 0 && index < sourceCount) {
      return `<a href="#" class="${SOURCE_REF_CLASSES}" data-source-index="${index}" onclick="event.preventDefault()">${num}</a>`
    }
    return match
  })
}
