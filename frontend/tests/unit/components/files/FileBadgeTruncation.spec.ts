import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FileVectorPill from '@/components/files/FileVectorPill.vue'
import FileSourceBadge from '@/components/files/FileSourceBadge.vue'

/**
 * Both badges wrap their label in a `truncate`, but carried `whitespace-nowrap`
 * on the outer element too — which held them at full text width, so the truncate
 * never engaged. A long folder name then ran out of its column and under the
 * row's action icons. The label keeps the nowrap; the badge itself must be able
 * to shrink.
 */
describe('file badges shrink instead of overflowing their row', () => {
  const badges = [
    {
      name: 'FileVectorPill',
      wrapper: () =>
        mount(FileVectorPill, {
          props: {
            state: 'vectorized' as const,
            chunkCount: 42,
            groupKey: 'a-very-long-knowledge-folder-name-that-will-not-fit',
          },
        }),
      testId: 'file-vector-pill',
    },
    {
      name: 'FileSourceBadge',
      wrapper: () => mount(FileSourceBadge, { props: { source: 'nextcloud' as const } }),
      testId: 'file-source-badge',
    },
  ]

  it.each(badges)('$name can shrink below its label', ({ wrapper, testId }) => {
    const classes = wrapper().get(`[data-testid="${testId}"]`).classes()

    expect(classes).not.toContain('whitespace-nowrap')
    expect(classes).toContain('min-w-0')
    expect(classes).toContain('max-w-full')
    expect(classes).toContain('overflow-hidden')
  })

  it.each(badges)('$name still keeps its label on one line', ({ wrapper }) => {
    const label = wrapper().get('span > span')

    expect(label.classes()).toContain('truncate')
    expect(label.classes()).toContain('whitespace-nowrap')
  })
})
