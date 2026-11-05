import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiLiteracyCard from '@/components/literacy/AiLiteracyCard.vue'

const myAiLiteracy = vi.fn()
const authState = reactive<{ isAuthenticated: boolean; user: { id: number } | null }>({
  isAuthenticated: true,
  user: { id: 1 },
})

vi.mock('@/services/api/aiLiteracyApi', () => ({
  myAiLiteracy: (...args: unknown[]) => myAiLiteracy(...args),
  remindAiLiteracy: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

describe('AiLiteracyCard', () => {
  beforeEach(() => {
    myAiLiteracy.mockReset()
    authState.isAuthenticated = true
    authState.user = { id: 1 }
  })

  it('renders nothing when reading is not due', async () => {
    myAiLiteracy.mockResolvedValue({ required: false, version: '2026-11', due: false })
    const wrapper = mount(AiLiteracyCard)
    await flushPromises()
    expect(wrapper.find('[data-testid="card-ai-literacy"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('asks the person to read when the card is due', async () => {
    myAiLiteracy.mockResolvedValue({ required: true, version: '2026-11', due: true })
    const wrapper = mount(AiLiteracyCard)
    await flushPromises()
    expect(wrapper.get('[data-testid="card-ai-literacy"]').text()).toContain('Using AI well')
    expect(wrapper.find('[data-testid="btn-literacy-read"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-literacy-remind"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('reloads when authentication finishes and hides on logout', async () => {
    authState.isAuthenticated = false
    authState.user = null
    myAiLiteracy.mockResolvedValue({ required: true, version: '2026-11', due: true })
    const wrapper = mount(AiLiteracyCard)
    await flushPromises()
    expect(myAiLiteracy).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="card-ai-literacy"]').exists()).toBe(false)

    authState.isAuthenticated = true
    authState.user = { id: 42 }
    await flushPromises()
    expect(myAiLiteracy).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="card-ai-literacy"]').exists()).toBe(true)

    authState.isAuthenticated = false
    authState.user = null
    await flushPromises()
    expect(wrapper.find('[data-testid="card-ai-literacy"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
