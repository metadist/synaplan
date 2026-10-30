import { httpClient } from './httpClient'
import {
  DetachConversationFileResponseSchema,
  GetConversationFilesResponseSchema,
} from './conversationFileSchema'

/**
 * Files this chat can still use on later turns, and the detach that takes
 * one out of the chat without deleting it.
 */
export const conversationFilesApi = {
  async getConversationFiles(chatId: number) {
    return httpClient(`/api/v1/chats/${chatId}/files`, {
      method: 'GET',
      schema: GetConversationFilesResponseSchema,
    })
  },

  async detachConversationFile(chatId: number, fileId: number) {
    return httpClient(`/api/v1/chats/${chatId}/files/${fileId}/detach`, {
      method: 'POST',
      schema: DetachConversationFileResponseSchema,
    })
  },
}
