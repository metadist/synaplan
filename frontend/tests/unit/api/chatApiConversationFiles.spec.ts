import { describe, expect, it } from 'vitest'

import { GetConversationFilesResponseSchema } from '@/services/api/chatApi'

describe('GetConversationFilesResponseSchema', () => {
  it('accepts the chat file-history payload', () => {
    const parsed = GetConversationFilesResponseSchema.parse({
      success: true,
      files: [
        {
          id: 77,
          reference: 'file:77',
          name: 'contract.pdf',
          category: 'document',
          origin: 'uploaded',
          fileType: 'pdf',
          messageId: 100,
          hasText: true,
        },
      ],
    })

    expect(parsed.files[0]?.name).toBe('contract.pdf')
  })
})
