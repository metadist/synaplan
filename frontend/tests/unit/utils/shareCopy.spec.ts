import { describe, expect, it } from 'vitest'
import {
  defaultSharePermission,
  shareConsequenceKey,
  shareFindKey,
  SHARE_PERMISSIONS,
} from '@/utils/shareCopy'

describe('shareCopy', () => {
  it('defaults conversation to use and widget to read', () => {
    expect(defaultSharePermission('conversation')).toBe('use')
    expect(defaultSharePermission('widget')).toBe('read')
    expect(SHARE_PERMISSIONS.widget).not.toContain('use')
  })

  it('builds kind-specific i18n keys', () => {
    expect(shareConsequenceKey('conversation', 'use')).toBe(
      'iam.dialog.consequence.conversation.use'
    )
    expect(shareFindKey('assistant')).toBe('iam.dialog.find.assistant')
    expect(shareFindKey('agent')).toBe('iam.dialog.find.assistant')
    expect(shareConsequenceKey('agent', 'use')).toBe('iam.dialog.consequence.assistant.use')
    expect(defaultSharePermission('agent')).toBe('use')
  })
})
