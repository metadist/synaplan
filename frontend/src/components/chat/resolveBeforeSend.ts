/**
 * Decides whether the composer may clear its draft.
 *
 * `true` comes back as a boolean, so the click handler does not yield and the
 * soft keyboard stays up. Anything else is awaited, and `waiting` blocks a
 * second send until that decision settles.
 */
export function resolveBeforeSend(
  beforeSend: ((text: string) => boolean | Promise<boolean>) | undefined,
  text: string,
  waiting: { value: boolean }
): boolean | Promise<boolean> {
  if (!beforeSend) return true
  const verdict = beforeSend(text)
  if (verdict === true) return true
  // Return the original promise. Wrapping it would add a tick, and the click
  // handler has to resume on the same one it used to.
  if (typeof verdict !== 'object' || verdict === null) return verdict
  waiting.value = true
  const settle = () => {
    waiting.value = false
  }
  verdict.then(settle, settle)
  return verdict
}
