import { describe, expect, it } from 'vitest'
import { AdjustmentsHorizontalIcon } from '@heroicons/vue/24/outline'
import { isExactMatch, orderKinds } from '@/composables/search/groupOrder'
import type { SearchKind, SearchResult } from '@/composables/search/types'

const result = (kind: SearchKind, title: string, key?: string): SearchResult => ({
  id: `${kind}:${title}`,
  kind,
  title,
  icon: AdjustmentsHorizontalIcon,
  matchedBy: 'lexical',
  setting: key
    ? { type: 'toggle', key, current: 'true', options: [], scope: 'system', envPinned: false }
    : undefined,
})

const groups = (...items: SearchResult[]) => {
  const map = new Map<SearchKind, SearchResult[]>()
  for (const item of items) map.set(item.kind, [...(map.get(item.kind) ?? []), item])
  return map
}

describe('isExactMatch', () => {
  it('matches the title or a setting key, typed out or as words', () => {
    const groupsSetting = result('setting', 'Users & groups', 'FEATURE_IAM_GROUPS_ENABLED')
    expect(isExactMatch(groupsSetting, 'FEATURE_IAM_GROUPS_ENABLED')).toBe(true)
    expect(isExactMatch(groupsSetting, 'feature iam groups enabled')).toBe(true)
    expect(isExactMatch(groupsSetting, 'users groups')).toBe(true)
    expect(isExactMatch(groupsSetting, 'groups')).toBe(false)
    expect(isExactMatch(groupsSetting, '  ')).toBe(false)
  })
})

describe('orderKinds', () => {
  it('keeps the fixed order without an exact match', () => {
    const map = groups(
      result('setting', 'Users & groups'),
      result('command', 'New chat'),
      result('page', 'Groups page')
    )
    expect(orderKinds(map, 'grou')).toEqual(['command', 'page', 'setting'])
  })

  it('moves a group whose top hit is exact to the front', () => {
    const map = groups(
      result('command', 'Switch language to Deutsch'),
      result('setting', 'Users & groups', 'FEATURE_IAM_GROUPS_ENABLED')
    )
    expect(orderKinds(map, 'FEATURE_IAM_GROUPS_ENABLED')).toEqual(['setting', 'command'])
  })

  it('never lifts the ask rows', () => {
    const map = groups(result('page', 'Files'), result('ask', 'Files'))
    expect(orderKinds(map, 'files')).toEqual(['page', 'ask'])
  })
})
