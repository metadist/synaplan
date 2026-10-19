import { beforeEach, describe, expect, it, vi } from 'vitest'

// Connecting an own chat provider turns the Demo off on the server. The person
// must read that it happened, in place of the usual "saved", and get it back
// with one click.

const { push, success, error, freeApi } = vi.hoisted(() => ({
  push: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  freeApi: { syncAfterAutoLeave: vi.fn(), start: vi.fn() },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key} ${JSON.stringify(params)}` : key,
    locale: { value: 'de' },
  }),
}))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ push, success, error }),
}))
vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => freeApi }))

import { useFreeApiAutoLeave } from '@/composables/useFreeApiAutoLeave'

type Pushed = { message: string; duration: number; action: { label: string; onClick: () => void } }
const lastToast = (): Pushed => push.mock.calls.at(-1)?.[0] as Pushed

describe('useFreeApiAutoLeave', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    freeApi.syncAfterAutoLeave.mockResolvedValue(undefined)
  })

  it('leaves the surface its own toast when the Demo was not left', async () => {
    const shown = await useFreeApiAutoLeave().announce({ demoLeft: false }, 'Groq')

    expect(shown).toBe(false)
    expect(push).not.toHaveBeenCalled()
    expect(freeApi.syncAfterAutoLeave).not.toHaveBeenCalled()
  })

  it('says the provider took over when its defaults were applied, with Undo', async () => {
    const shown = await useFreeApiAutoLeave().announce(
      { demoLeft: true, defaultsApplied: true },
      'Groq'
    )

    expect(shown).toBe(true)
    expect(freeApi.syncAfterAutoLeave).toHaveBeenCalled()
    expect(lastToast().message).toBe('freeApi.autoLeave.switched {"provider":"Groq"}')
    expect(lastToast().action.label).toBe('freeApi.autoLeave.undo')
    expect(lastToast().duration).toBeGreaterThan(5000)
  })

  // Without applied defaults the earlier bindings are back, not the provider's.
  it('says the earlier settings are back when no defaults were applied', async () => {
    await useFreeApiAutoLeave().announce({ demoLeft: true }, 'my-lab')

    expect(lastToast().message).toBe('freeApi.autoLeave.left {"provider":"my-lab"}')
  })

  it('starts the Demo again on Undo, even though an own provider exists', async () => {
    freeApi.start.mockResolvedValue(undefined)
    await useFreeApiAutoLeave().announce({ demoLeft: true }, 'Groq')

    lastToast().action.onClick()

    await vi.waitFor(() => expect(success).toHaveBeenCalledWith('freeApi.autoLeave.undone'))
    expect(freeApi.start).toHaveBeenCalledWith('de', true)
  })

  // The server never starts the Demo beside an own endpoint, so no Undo is offered there.
  it('offers no Undo after an own endpoint and says why', async () => {
    const shown = await useFreeApiAutoLeave().announce({ demoLeft: true }, 'my-lab', {
      undo: false,
    })

    expect(shown).toBe(true)
    expect(freeApi.syncAfterAutoLeave).toHaveBeenCalled()
    expect(lastToast().message).toBe('freeApi.autoLeave.endpointLeft {"provider":"my-lab"}')
    expect(lastToast().action).toBeUndefined()
  })

  it('says the provider setup stays when Undo fails', async () => {
    freeApi.start.mockRejectedValue(new Error('refused'))
    await useFreeApiAutoLeave().announce({ demoLeft: true }, 'Groq')

    lastToast().action.onClick()

    await vi.waitFor(() =>
      expect(error).toHaveBeenCalledWith('freeApi.autoLeave.undoFailed {"provider":"Groq"}')
    )
  })
})
