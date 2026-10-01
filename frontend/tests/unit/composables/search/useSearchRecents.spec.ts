import { beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, reactive } from 'vue'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { clearSearchRecents, useSearchRecents } from '@/composables/search/useSearchRecents'
import type { SearchResult } from '@/composables/search/types'

const auth = reactive<{ user: { id: number } | null; isImpersonating: boolean }>({
  user: { id: 7 },
  isImpersonating: false,
})

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

const KEY = 'synaplan.search.recents.7'
const chat: SearchResult = {
  id: 'chat:1',
  kind: 'chat',
  title: 'Plumber invoice',
  route: '/?chat=1',
  icon: FolderIcon,
  matchedBy: 'lexical',
}

describe('useSearchRecents', () => {
  beforeEach(() => {
    localStorage.clear()
    auth.user = { id: 7 }
    auth.isImpersonating = false
  })

  it('stores a recent per user and removes it on logout', () => {
    const scope = effectScope()
    const recents = scope.run(() => useSearchRecents())!
    recents.remember(chat)
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toHaveLength(1)

    clearSearchRecents(7)
    expect(localStorage.getItem(KEY)).toBeNull()
    scope.stop()
  })

  it('neither shows nor writes recents while an admin impersonates someone', () => {
    localStorage.setItem(KEY, JSON.stringify([{ id: 'chat:9', kind: 'chat', title: 'Private' }]))
    auth.isImpersonating = true
    const scope = effectScope()
    const recents = scope.run(() => useSearchRecents())!

    expect(recents.recents.value).toEqual([])
    recents.remember(chat)
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toEqual([
      { id: 'chat:9', kind: 'chat', title: 'Private' },
    ])
    scope.stop()
  })
})
