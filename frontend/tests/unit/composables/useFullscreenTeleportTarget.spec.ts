import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { computed, nextTick } from 'vue'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'

describe('useFullscreenTeleportTarget', () => {
  beforeEach(() => {
    Object.defineProperty(document, 'fullscreenElement', {
      value: null,
      writable: true,
      configurable: true,
    })
  })

  afterEach(() => {
    ;(document.fullscreenElement as any) = null
  })

  it('should use body by default and switch to fullscreen element on fullscreenchange', async () => {
    const TestComponent = {
      template: '<div data-testid="target">{{ label }}</div>',
      setup() {
        const { teleportTarget } = useFullscreenTeleportTarget()
        const label = computed(() => {
          if (teleportTarget.value === 'body') {
            return 'body'
          }
          return (teleportTarget.value as HTMLElement).tagName
        })
        return { label }
      },
    }

    const wrapper = mount(TestComponent)
    expect(wrapper.text()).toContain('body')

    const fsEl = document.createElement('div')
    ;(document.fullscreenElement as any) = fsEl
    document.dispatchEvent(new Event('fullscreenchange'))
    await nextTick()

    expect(wrapper.text()).toContain('DIV')
    ;(document.fullscreenElement as any) = null
    document.dispatchEvent(new Event('fullscreenchange'))
    await nextTick()

    expect(wrapper.text()).toContain('body')
  })
})
