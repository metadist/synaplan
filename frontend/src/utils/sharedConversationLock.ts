export type ConversationAccess = 'owner' | 'read' | 'use' | null

/**
 * A shared conversation with read or use access is view-only.
 * An incognito session replaces that surface, so the lock does not apply
 * until the session ends and the shared conversation comes back.
 */
export function isSharedConversationLocked(
  access: ConversationAccess,
  incognitoActive: boolean
): boolean {
  if (incognitoActive) return false
  return access === 'read' || access === 'use'
}

export function canComposeChat(input: {
  guest: boolean
  incognito: boolean
  access: ConversationAccess
  sharingEnabled: boolean
  /**
   * False when no conversation is open. A blank chat belongs to the viewer.
   * Session reset clears access back to null without changing an already
   * empty selection, and sharing would otherwise hide the composer for good.
   */
  chatOpen?: boolean
  /**
   * The start page, with no messages loaded. A stored chat id can outlive
   * the list (sharing keeps it while the incoming list is still loading),
   * and an unresolved access answer must not hide the field there.
   */
  emptyThread?: boolean
}): boolean {
  if (input.guest || input.incognito) return true
  if (input.chatOpen === false) return true
  if (input.emptyThread && !isSharedConversationLocked(input.access, false)) return true
  if (isSharedConversationLocked(input.access, false)) return false
  if (input.access === 'owner') return true
  return !input.sharingEnabled
}
