import { z } from 'zod'

// Where a file came from, in the order the Library's source filter lists them.
// Mirrors File::SOURCES in the backend.
export const fileSourceSchema = z.enum([
  'web_upload',
  'chat_attachment',
  'outlook',
  'nextcloud',
  'opencloud',
  'owncloud',
  'whatsapp',
  'telegram',
  'widget',
  'api',
  'generated',
  'compute',
  'notion',
])
export type FileSource = z.infer<typeof fileSourceSchema>

/** Files the AI made (chat media and file-work results). One list, used everywhere. */
export const AI_CREATED_SOURCES = ['generated', 'compute'] as const
export const AI_CREATED_SOURCE_QUERY = AI_CREATED_SOURCES.join(',')

/**
 * Library browse shows the user's own files. An explicit source filter replaces
 * that exclusion, so choosing "AI-generated" lists those files instead.
 */
export function librarySourceParams(selectedSource: string): {
  source?: string
  excludeSource?: string
} {
  if (selectedSource) return { source: selectedSource }
  return { excludeSource: AI_CREATED_SOURCE_QUERY }
}
