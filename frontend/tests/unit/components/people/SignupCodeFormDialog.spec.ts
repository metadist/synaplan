import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import type { SignupCode } from '@/services/api/signupCodesApi'

const create = vi.fn()
const update = vi.fn()

vi.mock('@/services/api/signupCodesApi', () => ({
  signupCodesApi: {
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import SignupCodeFormDialog from '@/components/people/SignupCodeFormDialog.vue'
import { ApiError } from '@/services/api/httpClient'

const pilots: SignupCode = {
  id: 7,
  prefix: 'R7KQ',
  label: 'Pilot customers',
  level: 'TEAM',
  maxUses: 5,
  uses: 2,
  expiresAt: 1789000000,
  active: true,
  state: 'active',
  createdAt: 1761400000,
  createdById: 1,
  createdByEmail: 'admin@example.com',
}

let wrapper: VueWrapper | null = null

async function openDialog(code: SignupCode | null) {
  wrapper = mount(SignupCodeFormDialog, {
    props: { open: false, code },
    attachTo: document.body,
    global: { stubs: { teleport: true } },
  })
  await wrapper.setProps({ open: true })
  await flushPromises()
  return wrapper
}

describe('SignupCodeFormDialog', () => {
  beforeEach(() => {
    create.mockReset()
    update.mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('leaves an exact expiry alone when only the label changes', async () => {
    update.mockResolvedValue({ ...pilots, label: 'Renamed' })
    const dialog = await openDialog(pilots)

    await dialog.get('[data-testid="input-signup-code-label"]').setValue('Renamed')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    expect(update).toHaveBeenCalledWith(7, { label: 'Renamed', maxUses: 5 })
  })

  it('sends the expiry when the administrator changed it', async () => {
    update.mockResolvedValue({ ...pilots, expiresAt: null })
    const dialog = await openDialog(pilots)

    await dialog.get('[data-testid="checkbox-signup-code-no-end"]').setValue(true)
    await dialog.get('form').trigger('submit')
    await flushPromises()

    expect(update).toHaveBeenCalledWith(7, {
      label: 'Pilot customers',
      maxUses: 5,
      expiresAt: null,
    })
  })

  it('cannot be dismissed while the code is being created, then shows it with focus on Copy', async () => {
    let resolveCreate: (value: unknown) => void = () => {}
    create.mockReturnValue(new Promise((resolve) => (resolveCreate = resolve)))
    const dialog = await openDialog(null)

    await dialog.get('[data-testid="input-signup-code-label"]').setValue('Pilots')
    await dialog.get('form').trigger('submit')
    await dialog.get('form button[type="button"]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(dialog.emitted('close')).toBeUndefined()

    resolveCreate({ success: true, code: 'R7KQ-M2XH-9TZA', signupCode: { ...pilots, uses: 0 } })
    await flushPromises()

    expect(dialog.get('[data-testid="text-signup-code-plain"]').text()).toBe('R7KQ-M2XH-9TZA')
    expect(document.activeElement).toBe(dialog.get('[data-testid="btn-signup-code-copy"]').element)
    expect(dialog.emitted('created')).toHaveLength(1)
  })

  it('says nothing was saved only when the server refused', async () => {
    create.mockRejectedValue(new ApiError(400, 'invalid', 'SIGNUP_CODE_REQUEST_INVALID'))
    const dialog = await openDialog(null)

    await dialog.get('[data-testid="input-signup-code-label"]').setValue('Pilots')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    expect(dialog.get('[data-testid="text-signup-code-form-error"]').text()).toBe(
      'The code was not created. Nothing was saved; try again.'
    )
    expect(dialog.emitted('refresh')).toBeUndefined()
  })

  it('does not claim nothing was saved when the answer was lost, and refreshes the list', async () => {
    create.mockRejectedValue(new TypeError('Failed to fetch'))
    const dialog = await openDialog(null)

    await dialog.get('[data-testid="input-signup-code-label"]').setValue('Pilots')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    expect(dialog.get('[data-testid="text-signup-code-form-error"]').text()).toContain(
      'We could not confirm whether the code was created.'
    )
    expect(dialog.emitted('refresh')).toHaveLength(1)
  })

  it('keeps Tab inside the panel', async () => {
    const dialog = await openDialog(null)
    const submit = dialog.get('[data-testid="btn-signup-code-submit"]').element as HTMLElement
    submit.focus()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', cancelable: true }))

    expect(document.activeElement).toBe(
      dialog.get('[data-testid="input-signup-code-label"]').element
    )
  })
})
