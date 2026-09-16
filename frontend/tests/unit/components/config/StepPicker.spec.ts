import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StepPicker from '@/components/config/workflows/StepPicker.vue'

describe('StepPicker', () => {
  it('lists built-in steps without developer jargon', () => {
    const wrapper = mount(StepPicker, { props: { modelValue: 'chat', tools: [] } })
    const text = wrapper.text()
    expect(text).toContain('Write an answer')
    expect(text).toContain('Only continue if')
    expect(text).toContain('Send to another system')
    expect(text).not.toContain('DAG')
    expect(text).not.toContain('node')
    expect(text).not.toContain('side effect')
  })

  it('emits the selected kind', async () => {
    const wrapper = mount(StepPicker, { props: { modelValue: 'chat', tools: [] } })
    await wrapper.get('[data-testid="btn-step-kind-condition"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['condition']])
  })
})
