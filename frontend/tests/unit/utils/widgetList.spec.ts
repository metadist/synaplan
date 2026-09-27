import { describe, expect, it } from 'vitest'
import { listedWidgets } from '@/utils/widgetList'

const owned = [{ id: 1, widgetId: 'own' }]
const shared = [{ id: 9, widgetId: 'shared' }]

describe('listedWidgets', () => {
  it('shows shared widgets on the same list as owned ones', () => {
    expect(listedWidgets(owned, shared, false).map((widget) => widget.widgetId)).toEqual([
      'own',
      'shared',
    ])
  })

  it('shows only shared widgets when that filter is on', () => {
    expect(listedWidgets(owned, shared, true)).toEqual(shared)
  })

  it('does not list the same widget twice', () => {
    expect(listedWidgets(owned, [{ id: 1, widgetId: 'own' }], false)).toEqual(owned)
  })

  it('keeps a shared widget visible when the person owns none', () => {
    expect(listedWidgets([], shared, false)).toEqual(shared)
  })
})
