import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { SignupCode } from '@/services/api/signupCodesApi'

const list = vi.fn()
const revoke = vi.fn()
const update = vi.fn()
const confirm = vi.fn()
const push = vi.fn()
const config = { auth: { registrationMode: 'open' as 'open' | 'admission' } }

vi.mock('@/services/api/signupCodesApi', () => ({
  signupCodesApi: {
    list: (...args: unknown[]) => list(...args),
    revoke: (...args: unknown[]) => revoke(...args),
    update: (...args: unknown[]) => update(...args),
    create: vi.fn(),
    uses: vi.fn().mockResolvedValue([]),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
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
    push.mockReset()
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
