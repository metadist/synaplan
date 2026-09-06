import type { ChatKind } from '@/components/iam/ChatKindPill.vue'
import type { IamSharedItem } from '@/services/api/iamApi'

export type { ChatKind }

/** Filter buttons above a chat history list. `group` covers every incoming chat. */
export type ChatListFilter = 'all' | 'private' | 'group' | 'widget'

/** How an incoming (shared-with-me) conversation should be pilled. */
export function kindOfSharedItem(item: IamSharedItem): { kind: ChatKind; label: string | null } {
  switch (item.sharedVia?.type) {
    case 'group':
      return { kind: 'group', label: item.sharedVia.name || null }
    case 'everyone':
      return { kind: 'everyone', label: null }
    default:
      return { kind: 'direct', label: item.ownerName ?? null }
  }
}

/** Whether a chat of `kind` belongs under the chosen filter button. */
export function matchesChatFilter(kind: ChatKind, filter: ChatListFilter): boolean {
  switch (filter) {
    case 'all':
      return true
    case 'private':
      return kind === 'private'
    case 'widget':
      return kind === 'widget'
    case 'group':
      return kind === 'group' || kind === 'everyone' || kind === 'direct'
  }
}
