import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { SignupCode } from '@/services/api/signupCodesApi'

const list = vi.fn()
const revoke = vi.fn()
const update = vi.fn()
const confirm = vi.fn()
const uses = vi.fn()
const show = vi.fn()
const push = vi.fn()
const success = vi.fn()
const warning = vi.fn()
const config = { auth: { registrationMode: 'open' as 'open' | 'admission' } }

vi.mock('@/services/api/signupCodesApi', () => ({
  signupCodesApi: {
    list: (...args: unknown[]) => list(...args),
    revoke: (...args: unknown[]) => revoke(...args),
    update: (...args: unknown[]) => update(...args),
    create: vi.fn(),
    uses: (...args: unknown[]) => uses(...args),
    show: (...args: unknown[]) => show(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, warning, error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: (...args: unknown[]) => confirm(...args) }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => config,
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

import SignupCodesTab from '@/components/people/SignupCodesTab.vue'

const pilots: SignupCode = {
  id: 7,
  prefix: 'R7KQ',
  label: 'Pilot customers',
  level: 'TEAM',
  maxUses: 5,
  uses: 2,
  expiresAt: null,
  active: true,
  codeStored: true,
  state: 'active',
  createdAt: 1761400000,
  createdById: 1,
  createdByEmail: 'admin@example.com',
}

function mountTab() {
  return mount(SignupCodesTab, { global: { stubs: { SignupCodeFormDialog: true } } })
}

describe('SignupCodesTab', () => {
  beforeEach(() => {
    list.mockReset()
    revoke.mockReset()
    update.mockReset()
    confirm.mockReset()
    uses.mockReset()
    show.mockReset()
    uses.mockResolvedValue([])
    push.mockReset()
    success.mockReset()
    warning.mockReset()
    config.auth.registrationMode = 'open'
  })

  it('shows one sentence and one action when there is no code, and says codes wait for admission', async () => {
    list.mockResolvedValue([])
    const wrapper = mountTab()
    await flushPromises()

    const empty = wrapper.get('[data-testid="empty-signup-codes"]')
    expect(empty.text()).toContain(
      'No invitation codes yet. Create one to let someone in without payment.'
    )
    expect(wrapper.findAll('[data-testid="btn-signup-code-create"]')).toHaveLength(1)
    expect(wrapper.get('[data-testid="text-signup-codes-open-mode"]').text()).toContain(
      'Sign-up is open on this server'
    )

    await wrapper.get('[data-testid="btn-signup-codes-open-settings"]').trigger('click')
    expect(push).toHaveBeenCalledWith({ name: 'admin-config', query: { tab: 'auth' } })
  })

  it('answers who made a code, what it grants, how long and how often on the row', async () => {
    config.auth.registrationMode = 'admission'
    list.mockResolvedValue([pilots])
    const wrapper = mountTab()
    await flushPromises()

    expect(wrapper.find('[data-testid="text-signup-codes-open-mode"]').exists()).toBe(false)
    const row = wrapper.get('[data-testid="item-signup-code"]')
    expect(row.get('[data-testid="text-signup-code-prefix"]').text()).toBe('R7KQ…')
    expect(row.get('[data-testid="text-signup-code-state"]').text()).toBe('Usable')
    expect(row.get('[data-testid="text-signup-code-facts"]').text()).toMatch(
      /Opens a Team account ·\s+no end date ·\s+2 of 5 uses/
    )
    expect(row.text()).toContain('Created by admin@example.com on')
    expect(row.text()).not.toContain('R7KQ-')
  })

  it('names how many people keep their accounts before revoking', async () => {
    list.mockResolvedValue([pilots])
    confirm.mockResolvedValue(false)
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="btn-signup-code-revoke"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'Revoke this code?',
        message: 'Nobody can use this code any more. The 2 people who used it keep their accounts.',
        danger: true,
      })
    )
    expect(revoke).not.toHaveBeenCalled()
  })

  it('revokes from the row and restores from the same row', async () => {
    list.mockResolvedValue([pilots])
    confirm.mockResolvedValue(true)
    revoke.mockResolvedValue({ ...pilots, active: false, state: 'revoked' })
    update.mockResolvedValue({ ...pilots })
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="btn-signup-code-revoke"]').trigger('click')
    await flushPromises()
    expect(revoke).toHaveBeenCalledWith(7)
    expect(wrapper.get('[data-testid="text-signup-code-state"]').text()).toBe('Revoked')

    await wrapper.get('[data-testid="btn-signup-code-restore"]').trigger('click')
    await flushPromises()
    expect(update).toHaveBeenCalledWith(7, { active: true })
    expect(wrapper.get('[data-testid="text-signup-code-state"]').text()).toBe('Usable')
  })

  it('does not claim a restored code works when it has expired', async () => {
    const revoked = { ...pilots, active: false, state: 'revoked' as const }
    list.mockResolvedValue([revoked])
    update.mockResolvedValue({ ...pilots, expiresAt: 1, state: 'expired' })
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="btn-signup-code-restore"]').trigger('click')
    await flushPromises()

    expect(success).not.toHaveBeenCalled()
    expect(warning).toHaveBeenCalledWith(
      'The code is no longer revoked, but its end date has passed. Set a later date under Edit so people can use it.'
    )
    expect(wrapper.get('[data-testid="text-signup-code-state"]').text()).toBe('Expired')
  })

  it('counts accounts that were deleted after using the code, and never fades a revoked row', async () => {
    list.mockResolvedValue([{ ...pilots, active: false, state: 'revoked' }])
    const wrapper = mountTab()
    await flushPromises()

    const row = wrapper.get('[data-testid="item-signup-code"]')
    expect(row.classes()).not.toContain('opacity-60')
    await row.get('[data-testid="btn-signup-code-uses"]').trigger('click')
    await flushPromises()

    expect(row.find('[data-testid="text-signup-code-uses-empty"]').exists()).toBe(false)
    expect(row.get('[data-testid="text-signup-code-uses-deleted"]').text()).toBe(
      '2 accounts that used this code have since been deleted.'
    )
  })

  it('reloads the list when a revoke was not confirmed instead of saying it failed', async () => {
    list.mockResolvedValue([pilots])
    confirm.mockResolvedValue(true)
    revoke.mockRejectedValue(new TypeError('Failed to fetch'))
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="btn-signup-code-revoke"]').trigger('click')
    await flushPromises()

    expect(warning).toHaveBeenCalledWith(
      'We could not confirm the change. The list now shows the current state of the code.'
    )
    expect(list).toHaveBeenCalledTimes(2)
  })

  it('reloads who used the code when its counter changes', async () => {
    list.mockResolvedValue([{ ...pilots, uses: 0 }])
    confirm.mockResolvedValue(true)
    revoke.mockResolvedValue({ ...pilots, uses: 1, active: false, state: 'revoked' })
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="btn-signup-code-uses"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="btn-signup-code-revoke"]').trigger('click')
    await flushPromises()

    expect(uses).toHaveBeenCalledTimes(2)
  })

  it('shows the full code on click so it can be copied again', async () => {
    list.mockResolvedValue([pilots])
    show.mockResolvedValue('R7KQ-M2XH-9TZA')
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="text-signup-code-prefix"]').trigger('click')
    await flushPromises()

    expect(show).toHaveBeenCalledWith(7)
    expect(wrapper.get('[data-testid="text-signup-code-full"]').text()).toBe('R7KQ-M2XH-9TZA')
    expect(wrapper.find('[data-testid="btn-signup-code-row-copy"]').exists()).toBe(true)
    await wrapper.get('[data-testid="btn-signup-code-hide"]').trigger('click')
    expect(wrapper.find('[data-testid="text-signup-code-full"]').exists()).toBe(false)
  })

  it('explains why a code from before codes were kept cannot be shown', async () => {
    list.mockResolvedValue([{ ...pilots, codeStored: false }])
    const wrapper = mountTab()
    await flushPromises()

    await wrapper.get('[data-testid="text-signup-code-prefix"]').trigger('click')
    await flushPromises()

    expect(show).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="text-signup-code-show-message"]').text()).toContain(
      'created before codes were kept'
    )
  })

  it('says the list did not load instead of showing an empty page', async () => {
    list.mockRejectedValue(new Error('down'))
    const wrapper = mountTab()
    await flushPromises()

    expect(wrapper.get('[data-testid="text-signup-codes-error"]').text()).toBe(
      'The invitation codes could not be loaded. Nothing changed; try again.'
    )
    expect(wrapper.find('[data-testid="empty-signup-codes"]').exists()).toBe(false)
  })
})
