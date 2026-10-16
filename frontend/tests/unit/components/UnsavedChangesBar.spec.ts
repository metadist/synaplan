import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'

describe('UnsavedChangesBar', () => {
  it('should render when show is true', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true },
    })
    expect(wrapper.find('.surface-card').exists()).toBe(true)
  })

  it('should not render when show is false', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: false },
    })
    expect(wrapper.find('.surface-card').exists()).toBe(false)
  })

  it('should call the save listener on save button click', async () => {
    const onSave = vi.fn()
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, onSave },
    })

    await wrapper.find('button[class*="btn-primary"]').trigger('click')
    expect(onSave).toHaveBeenCalledOnce()
  })

  it('returns to Save Changes after a failed save while the bar stays open', async () => {
    let calls = 0
    const wrapper = mount(UnsavedChangesBar, {
      props: {
        show: true,
        onSave: () => {
          calls += 1
          return Promise.reject(new Error('Current password is incorrect'))
        },
      },
    })

    const save = wrapper.get('[data-testid="btn-unsaved-save"]')
    await save.trigger('click')
    await flushPromises()

    expect(save.text()).toContain('Save Changes')
    expect(
      wrapper.get('[data-testid="btn-unsaved-discard"]').attributes('disabled')
    ).toBeUndefined()

    await save.trigger('click')
    expect(calls).toBe(2)
  })

  it('keeps Save disabled while the save listener is still running', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, onSave: () => gate },
    })

    const pending = wrapper.get('[data-testid="btn-unsaved-save"]').trigger('click')
    await nextTick()

    expect(wrapper.get('[data-testid="btn-unsaved-save"]').text()).toContain('Saving...')
    expect(wrapper.get('[data-testid="btn-unsaved-discard"]').attributes('disabled')).toBeDefined()

    release()
    await pending
    await flushPromises()

    expect(wrapper.get('[data-testid="btn-unsaved-save"]').text()).toContain('Save Changes')
  })

  it('should emit discard event on discard button click', async () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true },
    })

    const buttons = wrapper.findAll('button')
    await buttons[0].trigger('click')
    expect(wrapper.emitted('discard')).toBeTruthy()
  })

  it('should show preview button when showPreview is true', () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, showPreview: true },
    })

    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBe(3)
  })

  it('should emit preview event', async () => {
    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true, showPreview: true },
    })

    const buttons = wrapper.findAll('button')
    await buttons[1].trigger('click')
    expect(wrapper.emitted('preview')).toBeTruthy()
  })

  it('matches the content column instead of the viewport', async () => {
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    const host = document.createElement('div')
    document.body.appendChild(host)

    const wrapper = mount(UnsavedChangesBar, {
      props: { show: true },
      attachTo: host,
    })
    await flushPromises()

    const bar = wrapper.get('[data-testid="section-unsaved-bar"]')
    const parent = bar.element.parentElement
    if (!parent) throw new Error('bar has no parent')
    parent.style.paddingLeft = '16px'
    parent.style.paddingRight = '16px'
    vi.spyOn(parent, 'getBoundingClientRect').mockReturnValue({
      x: 320,
      y: 0,
      top: 0,
      left: 320,
      right: 1312,
      bottom: 400,
      width: 992,
      height: 400,
      toJSON: () => ({}),
    })
    window.dispatchEvent(new Event('resize'))
    await flushPromises()

    expect(bar.classes()).not.toContain('left-0')
    expect(bar.classes()).not.toContain('right-0')
    expect(bar.attributes('style')).toContain('left: 336px')
    expect(bar.attributes('style')).toContain('width: 960px')

    wrapper.unmount()
    host.remove()
    vi.unstubAllGlobals()
  })
})
