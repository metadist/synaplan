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
      .mockRejectedValueOnce(new Error('offline'))
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
        throw new Error('offline')
      })
    )
    await reloadConfig()
    expect(getConfigSync().setup?.wizardRequired).toBe(true)
  })
})
