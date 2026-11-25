import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EmailChangeView from '@/views/EmailChangeView.vue'
import { ApiError } from '@/services/api/httpClient'

const { mockConfirm, query } = vi.hoisted(() => ({
  mockConfirm: vi.fn(),
  query: { value: {} as Record<string, string> },
}))

vi.mock('@/services/api/emailChangeApi', () => ({ confirmEmailChange: mockConfirm }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: query.value }) }))

function mountView() {
  return mount(EmailChangeView, {
    global: {
      mocks: {
        $t: (key: string, params?: Record<string, string>) =>
          params ? `${key} ${params.email}` : key,
      },
    },
  })
}

describe('EmailChangeView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    query.value = {}
  })

  it('confirms the token from the link and names the new address', async () => {
    query.value = { token: 'abc' }
    mockConfirm.mockResolvedValue({ success: true, email: 'new@example.com' })

    const wrapper = mountView()
    expect(wrapper.find('[data-testid="text-opening"]').exists()).toBe(true)
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledWith('abc')
    expect(wrapper.get('[data-testid="text-done"]').text()).toBe(
      'auth.emailChangeDone new@example.com'
    )
  })

  it('says the link is dead when the server refuses it', async () => {
    query.value = { token: 'used' }
    mockConfirm.mockRejectedValue(new ApiError(400, 'refused', 'EMAIL_CHANGE_INVALID'))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="text-invalid"]').text()).toBe('auth.emailChangeInvalid')
  })

  it('keeps the link usable when the server is down, and retries it', async () => {
    query.value = { token: 'abc' }
    mockConfirm.mockRejectedValueOnce(new ApiError(500, 'down'))
    mockConfirm.mockResolvedValueOnce({ success: true, email: 'new@example.com' })

    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.find('[data-testid="text-invalid"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-failed"]').text()).toBe('auth.emailChangeFailed')

    await wrapper.get('[data-testid="btn-retry"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="text-done"]').exists()).toBe(true)
  })

  it('does not call the server without a token', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(mockConfirm).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="text-invalid"]').exists()).toBe(true)
  })
})
