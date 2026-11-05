import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiLiteracyCard from '@/components/literacy/AiLiteracyCard.vue'

const myAiLiteracy = vi.fn()

vi.mock('@/services/api/aiLiteracyApi', () => ({
  myAiLiteracy: (...args: unknown[]) => myAiLiteracy(...args),
  remindAiLiteracy: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAuthenticated: true }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

describe('AiLiteracyCard', () => {
  it('renders nothing when reading is not due', async () => {
    myAiLiteracy.mockResolvedValue({ required: false, version: '2026-11', due: false })
    const wrapper = mount(AiLiteracyCard)
    await flushPromises()
    expect(wrapper.find('[data-testid="card-ai-literacy"]').exists()).toBe(false)
  })

  it('asks the person to read when the card is due', async () => {
    myAiLiteracy.mockResolvedValue({ required: true, version: '2026-11', due: true })
    const wrapper = mount(AiLiteracyCard)
    await flushPromises()
    expect(wrapper.get('[data-testid="card-ai-literacy"]').text()).toContain('Using AI well')
    expect(wrapper.find('[data-testid="btn-literacy-read"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-literacy-remind"]').exists()).toBe(true)
  })
})
