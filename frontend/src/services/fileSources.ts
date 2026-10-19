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
])
export type FileSource = z.infer<typeof fileSourceSchema>
