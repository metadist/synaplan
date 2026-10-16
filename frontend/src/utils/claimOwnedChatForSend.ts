/**
 * A message on the blank page needs an owned chat before it appears.
 * Suppress history loading before the chat is opened: the selection watcher
 * would otherwise replace the message the person just sent.
 */
export async function claimOwnedChatForSend(input: {
  activeChatId: number | null
  suppressHistoryLoad: () => void
  releaseHistoryLoad: () => void
  openOwnedChat: () => Promise<void>
  readActiveChatId: () => number | null
}): Promise<number | null> {
  if (input.activeChatId != null) {
    return input.activeChatId
  }

  input.suppressHistoryLoad()
  await input.openOwnedChat()
  const chatId = input.readActiveChatId()
  if (chatId == null) {
    input.releaseHistoryLoad()
  }
  return chatId
}
