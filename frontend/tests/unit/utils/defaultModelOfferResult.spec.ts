import { describe, expect, it } from 'vitest'
import { interpretDefaultSave } from '@/utils/defaultModelOfferResult'

describe('interpretDefaultSave', () => {
  it('treats a skipped capability as an unchanged default', () => {
    expect(interpretDefaultSave({ skipped: { CHAT: 9 } }, 'CHAT', 9)).toEqual({ kind: 'skipped' })
  })

  it('reports the model that actually answers when the saved one does not', () => {
    expect(interpretDefaultSave({ replaced: { CHAT: 12 } }, 'CHAT', 9)).toEqual({
      kind: 'replaced',
      modelId: 12,
    })
  })

  it('reports an unusable save when nothing can take over', () => {
    expect(interpretDefaultSave({ replaced: { CHAT: null } }, 'CHAT', 9)).toEqual({
      kind: 'unusable',
      modelId: 9,
    })
  })

  it('reports the requested model when that is what will answer', () => {
    expect(interpretDefaultSave({ success: true }, 'CHAT', 9)).toEqual({
      kind: 'applied',
      modelId: 9,
    })
  })
})
