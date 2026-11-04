import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  beginDevPiniaRecovery,
  finishDevPiniaRecovery,
  isDevPiniaContextLoss,
  noteDevPiniaContextHealthy,
  reloadAfterDevPiniaContextLoss,
  resetDevPiniaContextRecovery,
} from '@/utils/devPiniaContextRecovery'

const PINIA_ERROR = new Error(
  '[🍍]: "getActivePinia()" was called but there was no active Pinia. Are you trying to use a store before calling "app.use(pinia)"?'
)

describe('devPiniaContextRecovery', () => {
  let reloads = 0

  beforeEach(() => {
    reloads = 0
    resetDevPiniaContextRecovery(() => {
      reloads += 1
    })
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })

  afterEach(() => {
    resetDevPiniaContextRecovery()
    vi.restoreAllMocks()
  })

  it('recognises the Pinia context error and ignores other failures', () => {
    expect(isDevPiniaContextLoss(PINIA_ERROR)).toBe(true)
    expect(isDevPiniaContextLoss(new Error('component failed'))).toBe(false)
    expect(isDevPiniaContextLoss('nope')).toBe(false)
  })

  it('reloads once and tells the caller to skip the fatal screen', () => {
    expect(reloadAfterDevPiniaContextLoss(PINIA_ERROR)).toBe(true)
    expect(reloadAfterDevPiniaContextLoss(PINIA_ERROR)).toBe(true)
    expect(reloads).toBe(1)
  })

  it('does not reload a second time after the page already refreshed for this failure', () => {
    expect(reloadAfterDevPiniaContextLoss(PINIA_ERROR)).toBe(true)
    expect(reloads).toBe(1)

    let secondPageReloads = 0
    resetDevPiniaContextRecovery(() => {
      secondPageReloads += 1
    })
    sessionStorage.setItem('synaplan.devPiniaReload', '1')

    expect(reloadAfterDevPiniaContextLoss(PINIA_ERROR)).toBe(false)
    expect(secondPageReloads).toBe(0)
  })

  it('does not reload ordinary errors', () => {
    expect(reloadAfterDevPiniaContextLoss(new Error('component failed'))).toBe(false)
    expect(reloads).toBe(0)
  })

  it('allows another reload after the remounted page is healthy', () => {
    beginDevPiniaRecovery()
    sessionStorage.setItem('synaplan.devPiniaReload', '1')
    noteDevPiniaContextHealthy()
    expect(sessionStorage.getItem('synaplan.devPiniaReload')).toBe('1')

    finishDevPiniaRecovery()
    expect(sessionStorage.getItem('synaplan.devPiniaReload')).toBeNull()
    expect(reloadAfterDevPiniaContextLoss(PINIA_ERROR)).toBe(true)
    expect(reloads).toBe(1)
  })
})
