import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SimpleWidgetForm from '@/components/widgets/SimpleWidgetForm.vue'

// Mock the widgetsApi
vi.mock('@/services/api/widgetsApi', () => ({
  quickCreateWidget: vi.fn(),
}))

// Mock useNotification
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
  }),
}))

describe('SimpleWidgetForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should render the form with name and website inputs', () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    expect(wrapper.find('[data-testid="input-widget-name"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-website-url"]').exists()).toBe(true)
  })

  it('should disable create button when form is invalid', () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    const createButton = wrapper.find('[data-testid="btn-create"]')
    expect(createButton.attributes('disabled')).toBeDefined()
  })

  it('should enable create button when form is valid', async () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="input-widget-name"]').setValue('Test Widget')
    await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')

    const createButton = wrapper.find('[data-testid="btn-create"]')
    expect(createButton.attributes('disabled')).toBeUndefined()
  })

  it('should emit close event when cancel button is clicked', async () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="btn-cancel"]').trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('should emit close event when close button is clicked', async () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="btn-close"]').trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('should call quickCreateWidget on form submit', async () => {
    const { quickCreateWidget } = await import('@/services/api/widgetsApi')
    const mockWidget = {
      id: 1,
      widgetId: 'wdg_test123',
      name: 'Test Widget',
      taskPromptTopic: 'tools:widget-default',
      status: 'active',
      config: {},
      isActive: true,
      created: Date.now(),
      updated: Date.now(),
    }
    ;(quickCreateWidget as any).mockResolvedValue(mockWidget)

    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="input-widget-name"]').setValue('Test Widget')
    await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(quickCreateWidget).toHaveBeenCalledWith({
      name: 'Test Widget',
      websiteUrl: 'https://example.com',
    })
  })

  it('should emit created event with widget on successful creation', async () => {
    const { quickCreateWidget } = await import('@/services/api/widgetsApi')
    const mockWidget = {
      id: 1,
      widgetId: 'wdg_test123',
      name: 'Test Widget',
      taskPromptTopic: 'tools:widget-default',
      status: 'active',
      config: {},
      isActive: true,
      created: Date.now(),
      updated: Date.now(),
    }
    ;(quickCreateWidget as any).mockResolvedValue(mockWidget)

    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="input-widget-name"]').setValue('Test Widget')
    await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const emittedEvents = wrapper.emitted('created')
    expect(emittedEvents).toBeTruthy()
    expect(emittedEvents![0][0]).toEqual(mockWidget)
  })

  it('should require minimum 2 characters for name', async () => {
    const wrapper = mount(SimpleWidgetForm, {
      global: {
        stubs: {
          Teleport: true,
          Icon: true,
        },
      },
    })

    await wrapper.find('[data-testid="input-widget-name"]').setValue('A')
    await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')

    const createButton = wrapper.find('[data-testid="btn-create"]')
    expect(createButton.attributes('disabled')).toBeDefined()
  })
})
