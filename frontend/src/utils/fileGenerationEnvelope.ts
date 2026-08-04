/**
 * Detect an officemaker envelope while it is still arriving over SSE.
 *
 * The model may prepend conversational prose before the JSON object, so the
 * key cannot be assumed to be at the beginning of the stream. Requiring an
 * opening brace before the quoted key avoids hiding normal prose that merely
 * mentions BFILEPATH.
 */
export function looksLikeFileGenerationEnvelope(content: string): boolean {
  const keyMatch = /"BFILEPATH"\s*:/.exec(content)
  if (!keyMatch) return false

  return content.lastIndexOf('{', keyMatch.index) !== -1
}
