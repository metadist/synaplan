import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const listAdminGroups = vi.fn()
const getGroupConfig = vi.fn()
const listLocks = vi.fn()
const getModels = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listAdminGroups: (...args: unknown[]) => listAdminGroups(...args),
    getGroupConfig: (...args: unknown[]) => getGroupConfig(...args),
    putGroupConfig: vi.fn(),
    listLocks: (...args: unknown[]) => listLocks(...args),
    patchLocks: vi.fn(),
  },
}))

vi.mock('@/services/api/configApi', () => ({
  getModels: (...args: unknown[]) => getModels(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import PoliciesTab from '@/components/people/PoliciesTab.vue'

describe('PoliciesTab', () => {
  beforeEach(() => {
    listAdminGroups.mockReset()
    getGroupConfig.mockReset()
    listLocks.mockReset()
    getModels.mockReset()
    listAdminGroups.mockResolvedValue([
      {
        id: 3,
        name: 'Support',
        slug: 'support',
        description: '',
        kind: 'manual',
        memberCount: 1,
        created: 1,
        updated: 1,
      },
    ])
    getGroupConfig.mockResolvedValue({ settings: {}, conflicts: {} })
    listLocks.mockResolvedValue({})
    getModels.mockResolvedValue({
      success: true,
      models: {
        CHAT: [
          model({ id: 1, name: 'Chat Model', service: 'groq', providerId: 'groq', tag: 'chat' }),
        ],
        VECTORIZE: [
          model({
            id: 2,
            name: 'Embed Model',
            service: 'ollama',
            providerId: 'ollama',
            tag: 'vectorize',
          }),
        ],
      },
      providers: [],
    })
  })

  it('renders group policies when a group is selected', async () => {
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-policies"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Support')
    expect(getGroupConfig).toHaveBeenCalled()
  })

  it('lists allow-list models from every capability, not only chat', async () => {
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.get('[data-testid="list-allowed-models"]').text()).toContain('Chat Model')
    expect(wrapper.get('[data-testid="list-allowed-models"]').text()).toContain('Embed Model')
    expect(wrapper.find('[data-testid="check-allowed-groq:groq:chat"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="check-allowed-ollama:ollama:vectorize"]').exists()).toBe(
      true
    )
  })
})

function model(partial: {
  id: number
  name: string
  service: string
  providerId: string
  tag: string
}) {
  return {
    quality: 1,
    rating: 1,
    priceIn: 0,
    priceOut: 0,
    description: null,
    isSystemModel: false,
    features: [],
    ...partial,
  }
}
