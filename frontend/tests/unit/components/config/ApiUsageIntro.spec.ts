import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import ApiUsageIntro from '@/components/config/ApiUsageIntro.vue'
import { publicApiEndpoints } from '@/utils/publicApiEndpoints'

const { success, error } = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))

describe('ApiUsageIntro', () => {
  it('shows the three addresses and copies the one that was clicked', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    })

    const wrapper = mount(ApiUsageIntro)
    await flushPromises()

    const urls = Object.fromEntries(
      publicApiEndpoints().map((endpoint) => [endpoint.id, endpoint.url])
    )
    expect(wrapper.get('[data-testid="text-api-url-openai"]').text()).toBe(urls.openai)
    expect(wrapper.get('[data-testid="text-api-url-chat"]').text()).toBe(urls.chat)
    expect(wrapper.get('[data-testid="text-api-url-mcp"]').text()).toBe(urls.mcp)

    await wrapper.get('[data-testid="btn-copy-api-url-chat"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(urls.chat)
    expect(success).toHaveBeenCalled()
    wrapper.unmount()
  })
})
