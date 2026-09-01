import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FileSourceBadge from '@/components/files/FileSourceBadge.vue'

/**
 * The source badge wraps its label in a `truncate`, but carried
 * `whitespace-nowrap` on the outer element too — which held it at full text
 * width, so the truncate never engaged. A long label then ran out of its
 * column and under the row's action icons. The label keeps the nowrap; the
 * badge itself must be able to shrink.
 *
 * (`FileVectorPill` is icon-only, so it has no label to overflow.)
 */
describe('file badges shrink instead of overflowing their row', () => {
  const wrapper = () => mount(FileSourceBadge, { props: { source: 'nextcloud' as const } })
  const testId = 'file-source-badge'

  it('can shrink below its label', () => {
    const classes = wrapper().get(`[data-testid="${testId}"]`).classes()

    expect(classes).not.toContain('whitespace-nowrap')
    expect(classes).toContain('min-w-0')
    expect(classes).toContain('max-w-full')
    expect(classes).toContain('overflow-hidden')
  })

  it('still keeps its label on one line', () => {
    const label = wrapper().get('span > span')

    expect(label.classes()).toContain('truncate')
    expect(label.classes()).toContain('whitespace-nowrap')
  })
})
