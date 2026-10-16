import { isDefaultChatTitle } from '@/stores/chats'

/**
 * Title shown on an incoming chat row.
 * A legacy unnamed chat was stored as "#123". A real first-message preview
 * may also start with "#", so only an all-digit id is replaced.
 */
export function incomingChatTitle(name: string, newChatLabel: string): string {
  const title = name.trim()
  if (title === '' || /^#\d+$/.test(title) || isDefaultChatTitle(title, newChatLabel)) {
    return newChatLabel
  }
  return title
}
