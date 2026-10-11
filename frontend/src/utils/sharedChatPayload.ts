import { z } from 'zod'

// Issue #1175: user-uploaded attachments travel on the File entity relation,
// serialized by MessageApiFormatter as `files[]`.
const SharedChatFileSchema = z.object({
  id: z.number(),
  filename: z.string(),
  fileType: z.string(),
  filePath: z.string(),
  fileSize: z.number().nullable().optional(),
  fileMime: z.string().nullable().optional(),
})

const SharedChatMessageSchema = z.object({
  id: z.number(),
  text: z.string(),
  direction: z.enum(['IN', 'OUT']),
  timestamp: z.number(),
  topic: z.string().optional(),
  language: z.string().optional(),
  provider: z.string().optional(),
  file: z
    .object({
      path: z.string(),
      type: z.string(),
    })
    .nullable()
    .optional(),
  files: z.array(SharedChatFileSchema).optional(),
  ragSources: z
    .array(
      z.object({
        n: z.number(),
        chunkId: z.string(),
        fileId: z.number(),
        fileName: z.string(),
        groupKey: z.string(),
        score: z.number().nullable().optional(),
        startLine: z.number(),
        endLine: z.number(),
      })
    )
    .nullable()
    .optional(),
})

export const SharedChatPayloadSchema = z.object({
  success: z.boolean(),
  chat: z.object({
    title: z.string(),
    createdAt: z.string(),
  }),
  messages: z.array(SharedChatMessageSchema).optional(),
})
