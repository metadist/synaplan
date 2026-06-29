import { describe, it, expect, beforeEach } from 'vitest'
import { useNotification } from '@/composables/useNotification'

describe('useNotification', () => {
  beforeEach(() => {
    // Module-level singleton — clear between tests.
    useNotification().notifications.value.splice(0)
  })

  it('push() adds an actionable notification with a thumbnail + action', () => {
    const { push, notifications } = useNotification()
    let clicked = false

    const id = push({
      type: 'success',
      message: 'Your video is ready',
      thumbnailUrl: '/api/v1/files/uploads/x.mp4',
      action: { label: 'View', onClick: () => (clicked = true) },
      duration: 0,
    })

    expect(notifications.value).toHaveLength(1)
    const n = notifications.value[0]
    expect(n.id).toBe(id)
    expect(n.type).toBe('success')
    expect(n.thumbnailUrl).toBe('/api/v1/files/uploads/x.mp4')
    expect(n.action?.label).toBe('View')

    n.action?.onClick()
    expect(clicked).toBe(true)
  })

  it('legacy success()/error() helpers still work and carry no action', () => {
    const { success, error, notifications } = useNotification()
    success('saved', 0)
    error('failed', 0)

    expect(notifications.value).toHaveLength(2)
    expect(notifications.value[0]).toMatchObject({ type: 'success', message: 'saved' })
    expect(notifications.value[0].action).toBeUndefined()
    expect(notifications.value[1]).toMatchObject({ type: 'error', message: 'failed' })
  })
})
