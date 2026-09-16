import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const listAdminGroups = vi.fn()
const getGroupConfig = vi.fn()
const putGroupConfig = vi.fn()
const listLocks = vi.fn()
const patchLocks = vi.fn()
const getModels = vi.fn()
const success = vi.fn()
const error = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listAdminGroups: (...args: unknown[]) => listAdminGroups(...args),
    getGroupConfig: (...args: unknown[]) => getGroupConfig(...args),
    putGroupConfig: (...args: unknown[]) => putGroupConfig(...args),
    listLocks: (...args: unknown[]) => listLocks(...args),
    patchLocks: (...args: unknown[]) => patchLocks(...args),
  },
}))

vi.mock('@/services/api/configApi', () => ({
  getModels: (...args: unknown[]) => getModels(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import PoliciesTab from '@/components/people/PoliciesTab.vue'
import { ApiError } from '@/services/api/httpClient'

describe('PoliciesTab', () => {
  beforeEach(() => {
    listAdminGroups.mockReset()
    getGroupConfig.mockReset()
    putGroupConfig.mockReset()
    listLocks.mockReset()
    patchLocks.mockReset()
    getModels.mockReset()
    success.mockReset()
    error.mockReset()
    putGroupConfig.mockResolvedValue({ settings: {}, conflicts: {} })
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

  it('shows instance locks even when no group is selected', async () => {
    listAdminGroups.mockResolvedValue([])
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-policy-locks"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="lock-DEFAULTMODEL.CHAT"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-policy-defaults"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Select a group to edit its policies.')
    expect(wrapper.text()).toContain('These locks apply to the whole instance')
  })

  it('keeps the group chat default enabled and names the instance lock', async () => {
    listLocks.mockResolvedValue({ 'DEFAULTMODEL.CHAT': true })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    const chatSelect = wrapper.get('[data-testid="select-default-CHAT"]')
    expect(chatSelect.attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="hint-policy-lock-ignored"]').text()).toContain(
      'Not used while the instance lock is on.'
    )
  })

  it('keeps feature inherit selects enabled while an instance lock is on', async () => {
    listLocks.mockResolvedValue({ 'SAVEDTASKS.ENABLED': true })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    const select = wrapper.get('[data-testid="select-feature-SAVEDTASKS_ENABLED"]')
    expect(select.attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="hint-policy-lock-ignored"]').text()).toContain(
      'Not used while the instance lock is on.'
    )
  })

  it('explains a missing instance default when locking fails', async () => {
    patchLocks.mockRejectedValue(new ApiError(422, 'Cannot lock', 'iam.noInstanceDefault'))
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    await wrapper.get('[data-testid="lock-DEFAULTMODEL.CHAT"]').setValue(true)
    await flushPromises()

    expect(error).toHaveBeenCalledWith(
      'There is no instance default to lock. Set the instance value first, then lock it.'
    )
  })

  it('does not treat a stale group locked flag as an instance lock', async () => {
    getGroupConfig.mockResolvedValue({
      settings: {
        'DEFAULTMODEL.CHAT': { value: 'groq:groq:chat', source: 'group', locked: true },
      },
      conflicts: {},
    })
    listLocks.mockResolvedValue({ 'DEFAULTMODEL.CHAT': false })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="hint-policy-lock-ignored"]').exists()).toBe(false)
  })

  it('clears the lock-ignored hint after a successful unlock', async () => {
    listLocks.mockResolvedValue({ 'DEFAULTMODEL.CHAT': true })
    patchLocks.mockResolvedValue({ 'DEFAULTMODEL.CHAT': false })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="hint-policy-lock-ignored"]').exists()).toBe(true)
    await wrapper.get('[data-testid="lock-DEFAULTMODEL.CHAT"]').setValue(false)
    await flushPromises()

    expect(wrapper.find('[data-testid="hint-policy-lock-ignored"]').exists()).toBe(false)
  })

  it('does not accept lock clicks until instance locks have loaded', async () => {
    let resolveLocks: (value: Record<string, boolean>) => void = () => {}
    listLocks.mockReturnValue(
      new Promise<Record<string, boolean>>((resolve) => {
        resolveLocks = resolve
      })
    )
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(
      wrapper.get('[data-testid="lock-DEFAULTMODEL.CHAT"]').attributes('disabled')
    ).toBeDefined()

    resolveLocks({})
    await flushPromises()

    expect(
      wrapper.get('[data-testid="lock-DEFAULTMODEL.CHAT"]').attributes('disabled')
    ).toBeUndefined()
  })

  it('shows inherit, on, and off for group feature policies', async () => {
    getGroupConfig.mockResolvedValue({
      settings: {
        'SAVEDTASKS.ENABLED': { value: true, source: 'admin', locked: false },
        'DESKTOP_AGENT.ENABLED': { value: true, source: 'group', locked: false },
        'MULTITASK.ROUTING_ENABLED': { value: false, source: 'group', locked: false },
      },
      conflicts: {},
    })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    const inherit = wrapper.get('[data-testid="select-feature-SAVEDTASKS_ENABLED"]')
      .element as HTMLSelectElement
    const forcedOn = wrapper.get('[data-testid="select-feature-DESKTOP_AGENT_ENABLED"]')
      .element as HTMLSelectElement
    const forcedOff = wrapper.get('[data-testid="select-feature-MULTITASK_ROUTING_ENABLED"]')
      .element as HTMLSelectElement

    expect(inherit.value).toBe('inherit')
    expect(forcedOn.value).toBe('on')
    expect(forcedOff.value).toBe('off')
    expect(wrapper.get('[data-testid="section-policy-features"]').text()).toContain(
      'The instance default is on.'
    )
    expect(wrapper.get('[data-testid="section-policy-features"]').text()).toContain(
      'This group turns it on for its members.'
    )
    expect(wrapper.get('[data-testid="section-policy-features"]').text()).toContain(
      'This group turns it off for its members.'
    )
  })

  it('lists every group-editable feature key including tools and workflows', async () => {
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="select-feature-TOOLS_REGISTRY_ENABLED"]').exists()).toBe(
      true
    )
    expect(wrapper.find('[data-testid="select-feature-TOOLS_APPROVALS_ENABLED"]').exists()).toBe(
      true
    )
    expect(wrapper.find('[data-testid="select-feature-TOOLS_CUSTOM_HTTP_ENABLED"]').exists()).toBe(
      true
    )
    expect(wrapper.find('[data-testid="select-feature-WORKFLOWS_BUILDER_ENABLED"]').exists()).toBe(
      true
    )
  })

  it('uses the built-in ON default when routing has no instance row', async () => {
    getGroupConfig.mockResolvedValue({ settings: {}, conflicts: {} })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    expect(wrapper.get('[data-testid="hint-feature-MULTITASK_ROUTING_ENABLED"]').text()).toBe(
      'The instance default is on.'
    )
    expect(wrapper.get('[data-testid="hint-feature-SAVEDTASKS_ENABLED"]').text()).toBe(
      'The instance default is off.'
    )
  })

  it('sends null to inherit a feature instead of writing a deny row', async () => {
    getGroupConfig.mockResolvedValue({
      settings: {
        'MULTITASK.ROUTING_ENABLED': { value: false, source: 'group', locked: false },
      },
      conflicts: {},
    })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    const select = wrapper.get('[data-testid="select-feature-MULTITASK_ROUTING_ENABLED"]')
    await select.setValue('inherit')
    await wrapper.get('[data-testid="btn-save-policies"]').trigger('click')
    await flushPromises()

    expect(putGroupConfig).toHaveBeenCalledWith(3, {
      'MULTITASK.ROUTING_ENABLED': null,
    })
  })

  it('sends true and false when a group forces a feature on or off', async () => {
    getGroupConfig.mockResolvedValue({
      settings: {
        'SAVEDTASKS.ENABLED': { value: true, source: 'admin', locked: false },
      },
      conflicts: {},
    })
    setActivePinia(createPinia())
    const wrapper = mount(PoliciesTab)
    await flushPromises()

    await wrapper.get('[data-testid="select-feature-SAVEDTASKS_ENABLED"]').setValue('off')
    await wrapper.get('[data-testid="select-feature-DESKTOP_AGENT_ENABLED"]').setValue('on')
    await wrapper.get('[data-testid="btn-save-policies"]').trigger('click')
    await flushPromises()

    expect(putGroupConfig).toHaveBeenCalledWith(3, {
      'SAVEDTASKS.ENABLED': false,
      'DESKTOP_AGENT.ENABLED': true,
    })
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
