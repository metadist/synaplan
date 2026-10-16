import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BrandingStyleResetCard from '@/components/admin/BrandingStyleResetCard.vue'

const { mockConfirm, mockReset } = vi.hoisted(() => ({
  mockConfirm: vi.fn(),
  mockReset: vi.fn(),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: mockConfirm }),
}))

const mountCard = () =>
  mount(BrandingStyleResetCard, {
    props: { config: { resetBrandingStyle: mockReset } as never },
    global: { stubs: { Icon: true } },
  })

describe('BrandingStyleResetCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('names what resets and what stays', () => {
    const wrapper = mountCard()
    expect(wrapper.get('[data-testid="branding-style-reset"]').text()).toContain(
      'default Synaplan colors and fonts'
    )
    expect(wrapper.get('[data-testid="branding-style-reset"]').text()).toContain('stay as they are')
    expect(wrapper.get('[data-testid="btn-reset-branding-style"]').text()).toContain('Reset style')
  })

  it('resets only after the admin confirms', async () => {
    mockConfirm.mockResolvedValue(true)
    mockReset.mockResolvedValue(true)

    const wrapper = mountCard()
    await wrapper.get('[data-testid="btn-reset-branding-style"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledWith(
      expect.objectContaining({
        title: expect.stringContaining('defaults'),
        confirmText: expect.stringContaining('Reset style'),
      })
    )
    expect(mockReset).toHaveBeenCalledTimes(1)
  })

  it('does not touch the style when the admin cancels', async () => {
    mockConfirm.mockResolvedValue(false)

    const wrapper = mountCard()
    await wrapper.get('[data-testid="btn-reset-branding-style"]').trigger('click')
    await flushPromises()

    expect(mockReset).not.toHaveBeenCalled()
  })

  it('disables the button while the reset runs', async () => {
    mockConfirm.mockResolvedValue(true)
    let release!: () => void
    mockReset.mockReturnValue(new Promise((resolve) => (release = () => resolve(true))))

    const wrapper = mountCard()
    await wrapper.get('[data-testid="btn-reset-branding-style"]').trigger('click')
    await flushPromises()

    const button = wrapper.get('[data-testid="btn-reset-branding-style"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).toContain('Resetting')

    release()
    await flushPromises()
    expect(
      wrapper.get('[data-testid="btn-reset-branding-style"]').attributes('disabled')
    ).toBeUndefined()
  })
})
