import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SelfAwareEmptyHint from '@/components/chat/SelfAwareEmptyHint.vue'
import { useIncognitoStore } from '@/stores/incognito'

const features = { selfAware: false }

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ features }),
}))

describe('SelfAwareEmptyHint', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    features.selfAware = false
    useIncognitoStore().active = false
  })

  it('is visible when the self-aware flag is on', () => {
    features.selfAware = true
    const wrapper = mount(SelfAwareEmptyHint)

    expect(wrapper.find('[data-testid="self-aware-empty-hint"]').exists()).toBe(true)
    expect(wrapper.text()).toContain("Not sure what's possible here?")
    expect(wrapper.text()).toContain('Ask me what I can do.')
  })

  it('is hidden when the self-aware flag is off', () => {
    const wrapper = mount(SelfAwareEmptyHint)

    expect(wrapper.find('[data-testid="self-aware-empty-hint"]').exists()).toBe(false)
  })

  it('is hidden in incognito even when the flag is on', () => {
    features.selfAware = true
    useIncognitoStore().active = true
    const wrapper = mount(SelfAwareEmptyHint)

    expect(wrapper.find('[data-testid="self-aware-empty-hint"]').exists()).toBe(false)
  })

  it('sends the question text when the action is clicked', async () => {
    features.selfAware = true
    const wrapper = mount(SelfAwareEmptyHint)

    await wrapper.get('[data-testid="btn-self-aware-empty-hint"]').trigger('click')

    expect(wrapper.emitted('ask')).toEqual([['What can you do here?']])
  })
})
