import { z } from 'zod'

export const ConversationFileRowSchema = z.object({
  id: z.number().int().nullable(),
  reference: z.string(),
  name: z.string(),
  category: z.enum(['image', 'document', 'audio', 'video', 'other']),
  origin: z.enum(['attached', 'uploaded', 'generated']),
  fileType: z.string(),
  messageId: z.number().int().nullable(),
  hasText: z.boolean(),
  mime: z.string().optional(),
})

export type ConversationFileRow = z.infer<typeof ConversationFileRowSchema>

export const GetConversationFilesResponseSchema = z.object({
  success: z.boolean(),
  files: z.array(ConversationFileRowSchema),
})

export const DetachConversationFileResponseSchema = z.object({
  success: z.boolean(),
  detached: z.boolean(),
})
