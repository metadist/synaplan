import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useGlobalErrorStore } from '@/stores/globalError'

describe('useGlobalErrorStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('starts with no error', () => {
    const store = useGlobalErrorStore()
    expect(store.current).toBeNull()
    expect(store.hasError).toBe(false)
  })

  it('stores a structured payload via setError()', () => {
    const store = useGlobalErrorStore()

    store.setError({
      message: 'boom',
      statusCode: 500,
      reason: 'router_navigation',
      source: 'router:onError',
      stack: 'Error: boom\n  at foo',
    })

    expect(store.hasError).toBe(true)
    expect(store.current).toMatchObject({
      message: 'boom',
      statusCode: 500,
      reason: 'router_navigation',
      source: 'router:onError',
      stack: 'Error: boom\n  at foo',
    })
  })

  it('normalises a raw Error instance into a serialisable payload', () => {
    const store = useGlobalErrorStore()
    const err = new Error('kaboom')

    store.setError(err)

    expect(store.current).not.toBeNull()
    expect(store.current?.message).toBe('kaboom')
    expect(store.current?.reason).toBe('unknown')
    expect(typeof store.current?.stack).toBe('string')
  })

  it('clears the active error', () => {
    const store = useGlobalErrorStore()
    store.setError({ message: 'oops' })
    expect(store.hasError).toBe(true)

    store.clear()

    expect(store.hasError).toBe(false)
    expect(store.current).toBeNull()
  })

  it('replaces the existing payload on a subsequent setError() call', () => {
    const store = useGlobalErrorStore()
    store.setError({ message: 'first', statusCode: 500 })
    store.setError({ message: 'second', statusCode: 503 })

    expect(store.current?.message).toBe('second')
    expect(store.current?.statusCode).toBe(503)
  })
})
