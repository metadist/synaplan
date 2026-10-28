/**
 * How much a question needed, as the sorter rated it. The fields ride on the
 * assistant's reply: a `lookup` offers Look deeper under the answer, a notice
 * explains why a research the question needed was not started.
 */
export interface MessageEffort {
  /** `answer`, `lookup` or `research`. */
  effort?: string | null
  /** Why no research was started; the answer began with that sentence. */
  effortNotice?: string | null
}

/** The effort of a persisted row: an assistant reply carries it, a user turn never does. */
export function effortFields(role: string, row: MessageEffort): Required<MessageEffort> {
  const assistant = role === 'assistant'
  return {
    effort: assistant ? (row.effort ?? null) : null,
    effortNotice: assistant ? (row.effortNotice ?? null) : null,
  }
}

/** Carry the effort of the persisted reply onto the local one; a reply without one stays as is. */
export function adoptEffort(local: MessageEffort, persisted: MessageEffort): void {
  if (!persisted.effort) return
  local.effort = persisted.effort
  local.effortNotice = persisted.effortNotice ?? null
}
