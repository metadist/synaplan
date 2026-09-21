import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'
import { useAccordion } from '@/composables/useAccordion'

describe('useAccordion', () => {
  it('starts closed and toggles independently', () => {
    const { isOpen, toggle, allOpen } = useAccordion(['a', 'b', 'c'])

    expect(isOpen('a')).toBe(false)
    expect(isOpen('b')).toBe(false)
    expect(allOpen.value).toBe(false)

    toggle('b')
    expect(isOpen('a')).toBe(false)
    expect(isOpen('b')).toBe(true)

    toggle('a')
    expect(isOpen('a')).toBe(true)
    expect(isOpen('b')).toBe(true)
  })

  it('expands and collapses every panel', () => {
    const { isOpen, expandAll, collapseAll, allOpen } = useAccordion(['a', 'b'])

    expandAll()
    expect(isOpen('a')).toBe(true)
    expect(isOpen('b')).toBe(true)
    expect(allOpen.value).toBe(true)

    collapseAll()
    expect(isOpen('a')).toBe(false)
    expect(isOpen('b')).toBe(false)
    expect(allOpen.value).toBe(false)
  })

  it('closes every panel again when the id list changes', async () => {
    const ids = ref(['a', 'b'])
    const { isOpen, toggle } = useAccordion(ids)

    toggle('b')
    expect(isOpen('b')).toBe(true)

    ids.value = ['x', 'y']
    await nextTick()

    expect(isOpen('x')).toBe(false)
    expect(isOpen('y')).toBe(false)
    expect(isOpen('b')).toBe(false)
  })
})
