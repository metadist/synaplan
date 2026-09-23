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
}): boolean {
  if (input.guest || input.incognito) return true
  if (isSharedConversationLocked(input.access, false)) return false
  if (input.access === 'owner') return true
  return !input.sharingEnabled
}
