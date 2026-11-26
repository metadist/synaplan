import type { Message, Part } from '@/stores/history'

/**
 * A generated office file is stored as `__FILE_GENERATED__:name` on the first
 * line. The lines under it are what changed and have to stay in the bubble.
 */
export function generatedFileParts(content: string): { marker: string; rest: string } | null {
  if (!content.startsWith('__FILE_GENERATED__:')) {
    return null
  }
  const newline = content.indexOf('\n')
  const firstLine = (newline === -1 ? content : content.slice(0, newline)).trim()
  const filename = firstLine.replace('__FILE_GENERATED__:', '').trim()
  return {
    marker: `__FILE_GENERATED__:${filename}`,
    rest: newline === -1 ? '' : content.slice(newline).trim(),
  }
}

/**
 * The persisted row is the source of the download sentence and the change
 * note. A live bubble that still shows raw JSON, or only the translated
 * sentence, picks both up here.
 */
export function adoptGeneratedFileMarker(local: Message, persisted: Message): void {
  const persistedMarker = persisted.parts.find(
    (part) => part.type === 'text' && isFileGenerationMarker(part.content)
  )
  if (!persistedMarker) {
    return
  }
  const notes = changeNotes(persisted, persistedMarker)
  const alreadyShown = local.parts.some(
    (part) => part.type === 'text' && part.content === persistedMarker.content
  )
  if (!alreadyShown) {
    const preserved = local.parts.filter((part) => part.type !== 'text' && part.type !== 'code')
    local.parts = [persistedMarker, ...notes, ...preserved]
    return
  }
  for (const note of notes) {
    const present = local.parts.some(
      (part) => part.type === 'text' && part.content === note.content
    )
    if (!present) {
      local.parts.push(note)
    }
  }
}

function changeNotes(persisted: Message, marker: Part): Part[] {
  return persisted.parts.filter(
    (part) =>
      part.type === 'text' &&
      part !== marker &&
      !isFileGenerationMarker(part.content) &&
      Boolean(part.content?.trim())
  )
}

function isFileGenerationMarker(content: string | undefined): boolean {
  if (!content) {
    return false
  }
  return content.startsWith('__FILE_GENERATED__:') || content === '__FILE_GENERATION_FAILED__'
}
