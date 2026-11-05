/**
 * What a send with files and no typed text should show and what the
 * backend should hear.
 *
 * Inventing "Please review the attached file." was a new user command:
 * the sorter treated a screenshot of an in-flight how-to as a document
 * to summarise. Audio already sends empty text (#955). Images and
 * documents follow that contract. The bubble shows the file chip. Voice
 * notes keep a short placeholder so the row is not blank before the
 * player appears; that string is not forwarded.
 */
export function fileOnlyTurnMessages(
  content: string,
  hasFiles: boolean,
  hasOnlyAudio: boolean,
  voicePlaceholder: string
): { display: string; backend: string } {
  if (content.trim() !== '' || !hasFiles) {
    return { display: content, backend: content }
  }
  if (hasOnlyAudio) {
    return { display: voicePlaceholder, backend: '' }
  }
  return { display: '', backend: '' }
}
