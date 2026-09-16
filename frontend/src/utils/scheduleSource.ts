import type { Part } from '@/stores/history'

const TEXT_PART_TYPES = new Set(['text', 'pastedText'])

/**
 * Rebuild the user instruction that produced a task plan.
 *
 * Pasted blocks are stored as `pastedText` parts (and as
 * `<pasted-content>` in the persisted row). Dropping them when saving a
 * Saved Task would lose a URL that only lived in the paste.
 */
export function scheduleSourceFromParts(parts: Part[] | undefined): string {
  if (!parts?.length) return ''
  return parts
    .filter((part) => TEXT_PART_TYPES.has(part.type) && typeof part.content === 'string')
    .map((part) => part.content)
    .join('\n')
    .trim()
}

/** True when the instruction names a concrete http(s) URL, including markdown links. */
export function instructionHasUrl(text: string): boolean {
  return /https?:\/\//i.test(text)
}
