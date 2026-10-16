import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { computed } from 'vue'
import { reloadConfig, getConfigSync, clearRuntimeConfigCache } from '@/services/api/httpClient'

/**
 * Regression guard for the in-chat usage taximeter (and every other
 * config-derived Vue computed): the admin master switch must actually take
 * effect. `getConfigSync()` reads a reactive shallowRef, so a `computed` that
 * mirrors it re-evaluates when the runtime config (re)loads. Before the fix the
 * backing value was a plain module variable, so the computed latched onto the
 * pre-load default (`enabled ?? true`) and the toggle appeared to do nothing.
 */
describe('httpClient runtime config reactivity', () => {
  let enabledValue = true

  beforeEach(() => {
    clearRuntimeConfigCache()
    enabledValue = true
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({
        ok: true,
        json: async () => ({
          usageTaximeter: { enabled: enabledValue },
          unavailableProviders: [],
        }),
      }))
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('re-evaluates a computed reading getConfigSync() when the config is reloaded', async () => {
    const enabled = computed(() => getConfigSync().usageTaximeter?.enabled ?? true)

    await reloadConfig()
    expect(enabled.value).toBe(true)

    // Admin turns the master switch OFF and the app reloads runtime config.
    enabledValue = false
    await reloadConfig()

    expect(enabled.value).toBe(false)
  })

  it('retries a failed load and does not cache the fallback', async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          setup: { wizardRequired: true },
          usageTaximeter: { enabled: true },
          unavailableProviders: [],
        }),
      })
    vi.stubGlobal('fetch', fetchMock)

    await reloadConfig()
    expect(getConfigSync().setup?.wizardRequired).toBe(true)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('keeps the previous payload when every retry fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({
        ok: true,
        json: async () => ({
          setup: { wizardRequired: true },
          unavailableProviders: [],
        }),
      }))
    )
    await reloadConfig()
    expect(getConfigSync().setup?.wizardRequired).toBe(true)

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('offline')
      })
    )
    await reloadConfig()
    expect(getConfigSync().setup?.wizardRequired).toBe(true)
  })

  it('retries an abort once and does not retry a deterministic failure', async () => {
    const aborted = vi.fn().mockRejectedValue(new DOMException('aborted', 'AbortError'))
    vi.stubGlobal('fetch', aborted)
    await reloadConfig()
    expect(aborted).toHaveBeenCalledTimes(2)
    expect(getConfigSync().usageTaximeter?.enabled).toBe(true)

    clearRuntimeConfigCache()
    const rejected = vi.fn().mockResolvedValue({
      ok: false,
      status: 404,
      json: async () => ({}),
    })
    vi.stubGlobal('fetch', rejected)
    await reloadConfig()
    expect(rejected).toHaveBeenCalledTimes(1)
  })

  it('drops a stale response that lands after a newer reload', async () => {
    let finishStale: (value: unknown) => void = () => {}
    const stale = new Promise((resolve) => {
      finishStale = resolve
    })
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(() => stale)
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          setup: { wizardRequired: false },
          unavailableProviders: [],
        }),
      })
    vi.stubGlobal('fetch', fetchMock)

    const older = reloadConfig()
    await reloadConfig()
    expect(getConfigSync().setup?.wizardRequired).toBe(false)

    finishStale({
      ok: true,
      json: async () => ({
        setup: { wizardRequired: true },
        unavailableProviders: [],
      }),
    })
    await older
    expect(getConfigSync().setup?.wizardRequired).toBe(false)
  })
})
