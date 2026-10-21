/**
 * What a default-model save actually did.
 *
 * `skipped` means the row was not written. `replaced` means it was written,
 * but another model is what answers. `unusable` means it was written and
 * nothing can answer in its place.
 */
export type DefaultSaveOutcome =
  | { kind: 'skipped' }
  | { kind: 'applied'; modelId: number }
  | { kind: 'replaced'; modelId: number }
  | { kind: 'unusable'; modelId: number }

export function interpretDefaultSave(
  response: {
    skipped?: object | null
    replaced?: Record<string, number | null> | null
  },
  capability: string,
  requestedId: number
): DefaultSaveOutcome {
  if (response.skipped && capability in response.skipped) {
    return { kind: 'skipped' }
  }

  if (response.replaced && capability in response.replaced) {
    const effective = response.replaced[capability]
    if (typeof effective === 'number') {
      return { kind: 'replaced', modelId: effective }
    }

    return { kind: 'unusable', modelId: requestedId }
  }

  return { kind: 'applied', modelId: requestedId }
}
