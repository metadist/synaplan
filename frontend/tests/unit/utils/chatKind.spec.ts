import { describe, expect, it } from 'vitest'
import { kindOfSharedItem, kindOfSharedVia, matchesChatFilter } from '@/utils/chatKind'
import type { IamSharedItem } from '@/services/api/iamApi'

function item(overrides: Partial<IamSharedItem>): IamSharedItem {
  return {
    id: '13',
    name: 'Q3 playbook',
    icon: 'chat',
    permission: 'use',
    ownerId: 1,
    ownerName: 'Alice',
    sharedVia: { type: 'group', name: 'Sales' },
    sharedAt: 1_788_721_849,
    isNew: false,
    ...overrides,
  } as IamSharedItem
}

describe('kindOfSharedItem', () => {
  it('pills a group share with the group name', () => {
    expect(kindOfSharedItem(item({}))).toEqual({ kind: 'group', label: 'Sales' })
  })

  it('falls back to a generic group pill when the group has no name', () => {
    expect(kindOfSharedItem(item({ sharedVia: { type: 'group', name: '' } }))).toEqual({
      kind: 'group',
      label: null,
    })
  })

  it('pills an everyone share without a label', () => {
    expect(kindOfSharedItem(item({ sharedVia: { type: 'everyone', name: '' } }))).toEqual({
      kind: 'everyone',
      label: null,
    })
  })

  it('pills a direct share with the owner name', () => {
    expect(kindOfSharedItem(item({ sharedVia: { type: 'user', name: 'Me' } }))).toEqual({
      kind: 'direct',
      label: 'Alice',
    })
  })
})

describe('kindOfSharedVia', () => {
  it('maps a GET /chats sharedVia payload the same way as a list item', () => {
    expect(kindOfSharedVia({ type: 'group', name: 'Sales' }, 'Alice')).toEqual({
      kind: 'group',
      label: 'Sales',
    })
    expect(kindOfSharedVia({ type: 'everyone', name: '' }, 'Alice')).toEqual({
      kind: 'everyone',
      label: null,
    })
    expect(kindOfSharedVia({ type: 'user', name: '' }, 'Alice')).toEqual({
      kind: 'direct',
      label: 'Alice',
    })
  })
})

describe('matchesChatFilter', () => {
  it('shows everything under "all"', () => {
    for (const kind of ['private', 'group', 'everyone', 'direct', 'widget'] as const) {
      expect(matchesChatFilter(kind, 'all')).toBe(true)
    }
  })

  it('keeps only my own chats under "private"', () => {
    expect(matchesChatFilter('private', 'private')).toBe(true)
    expect(matchesChatFilter('group', 'private')).toBe(false)
    expect(matchesChatFilter('widget', 'private')).toBe(false)
  })

  it('groups every incoming kind under "group"', () => {
    expect(matchesChatFilter('group', 'group')).toBe(true)
    expect(matchesChatFilter('everyone', 'group')).toBe(true)
    expect(matchesChatFilter('direct', 'group')).toBe(true)
    expect(matchesChatFilter('private', 'group')).toBe(false)
    expect(matchesChatFilter('widget', 'group')).toBe(false)
  })

  it('isolates widget sessions under "widget"', () => {
    expect(matchesChatFilter('widget', 'widget')).toBe(true)
    expect(matchesChatFilter('private', 'widget')).toBe(false)
  })
})
