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
   * False when no conversation is open. A blank page belongs to the viewer.
   * A selected chat stays locked until ownership is known: an empty shared
   * thread looks the same while its access lookup is still pending, and
   * sending then would be rejected.
   */
  chatOpen?: boolean
}): boolean {
  if (input.guest || input.incognito) return true
  if (input.chatOpen === false) return true
  if (isSharedConversationLocked(input.access, false)) return false
  if (input.access === 'owner') return true
  return !input.sharingEnabled
}
