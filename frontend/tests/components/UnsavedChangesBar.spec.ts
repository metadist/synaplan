import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'

describe('UnsavedChangesBar', () => {
  it('should render when show is true', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true }
    })
    expect(wrapper.find('.surface-card').exists()).toBe(true)
  })

  it('should not render when show is false', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: false }
    })
    expect(wrapper.find('.surface-card').exists()).toBe(false)
  })

  it('should emit save event on save button click', async () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true }
    })
    
    await wrapper.find('button[class*="btn-primary"]').trigger('click')
    expect(wrapper.emitted('save')).toBeTruthy()
  })

  it('should emit discard event on discard button click', async () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true }
    })
    
    const buttons = wrapper.findAll('button')
    await buttons[0].trigger('click')
    expect(wrapper.emitted('discard')).toBeTruthy()
  })

  it('should show preview button when showPreview is true', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, showPreview: true }
    })
    
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBe(3)
  })

  it('should emit preview event', async () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, showPreview: true }
    })
    
    const buttons = wrapper.findAll('button')
    await buttons[1].trigger('click')
    expect(wrapper.emitted('preview')).toBeTruthy()
  })
})

