import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import WidgetSetupWizard from '@/components/widgets/setup-wizard/WidgetSetupWizard.vue'
import type { Widget } from '@/services/api/widgetsApi'

// Mock the widgetsApi
vi.mock('@/services/api/widgetsApi', () => ({
  quickCreateWidget: vi.fn(),
  updateWidget: vi.fn(),
  generateWidgetPrompt: vi.fn(),
}))

// Mock the promptsApi (also pulled in by the FilePicker inside StepKnowledge)
vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    uploadPromptFile: vi.fn(),
    linkFileToPrompt: vi.fn(),
    getAvailableFiles: vi.fn().mockResolvedValue([]),
  },
}))

// Mock useNotification
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
  }),
}))

const mockWidget = {
  id: 1,
  widgetId: 'wdg_test123',
  name: 'Test Widget',
  taskPromptTopic: 'tools:widget-default',
  status: 'active',
  config: { allowedDomains: ['example.com'] },
  isActive: true,
  created: Date.now(),
  updated: Date.now(),
} as Widget

const mountWizard = () =>
  mount(WidgetSetupWizard, {
    global: {
      stubs: {
        Teleport: true,
        Icon: true,
      },
    },
  })

const fillStepOne = async (wrapper: ReturnType<typeof mountWizard>) => {
  await wrapper.find('[data-testid="input-widget-name"]').setValue('Test Widget')
  await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')
}

const goToLastStep = async (wrapper: ReturnType<typeof mountWizard>) => {
  for (let i = 0; i < 3; i++) {
    await wrapper.find('[data-testid="btn-wizard-next"]').trigger('click')
  }
}

describe('WidgetSetupWizard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should render step 1 with name and website inputs', () => {
    const wrapper = mountWizard()

    expect(wrapper.find('[data-testid="input-widget-name"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-website-url"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="wizard-stepper"]').exists()).toBe(true)
  })

  it('should disable the next button while step 1 is invalid', async () => {
    const wrapper = mountWizard()

    expect(wrapper.find('[data-testid="btn-wizard-next"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-testid="input-widget-name"]').setValue('A')
    await wrapper.find('[data-testid="input-website-url"]').setValue('https://example.com')
    expect(wrapper.find('[data-testid="btn-wizard-next"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-testid="input-widget-name"]').setValue('Test Widget')
    expect(wrapper.find('[data-testid="btn-wizard-next"]').attributes('disabled')).toBeUndefined()
  })

  it('should emit close from cancel and close buttons', async () => {
    const wrapper = mountWizard()

    await wrapper.find('[data-testid="btn-cancel"]').trigger('click')
    await wrapper.find('[data-testid="btn-close"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(2)
  })

  it('should navigate through all four steps', async () => {
    const wrapper = mountWizard()
    await fillStepOne(wrapper)

    await wrapper.find('[data-testid="btn-wizard-next"]').trigger('click')
    expect(wrapper.find('[data-testid="input-primary-color"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-wizard-next"]').trigger('click')
    expect(wrapper.find('[data-testid="input-knowledge-files"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-wizard-next"]').trigger('click')
    expect(wrapper.find('[data-testid="input-welcome-message"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-create"]').exists()).toBe(true)
  })

  it('should navigate back with the back button', async () => {
    const wrapper = mountWizard()
    await fillStepOne(wrapper)

    await wrapper.find('[data-testid="btn-wizard-next"]').trigger('click')
    await wrapper.find('[data-testid="btn-wizard-back"]').trigger('click')
    expect(wrapper.find('[data-testid="input-widget-name"]').exists()).toBe(true)
  })

  it('should create the widget and apply the config on finish', async () => {
    const { quickCreateWidget, updateWidget, generateWidgetPrompt } =
      await import('@/services/api/widgetsApi')
    vi.mocked(quickCreateWidget).mockResolvedValue({ ...mockWidget })
    vi.mocked(updateWidget).mockResolvedValue(undefined)

    const wrapper = mountWizard()
    await fillStepOne(wrapper)
    await goToLastStep(wrapper)

    await wrapper.find('[data-testid="btn-create"]').trigger('click')
    await flushPromises()

    expect(quickCreateWidget).toHaveBeenCalledWith({
      name: 'Test Widget',
      websiteUrl: 'https://example.com',
    })
    expect(updateWidget).toHaveBeenCalledWith(
      'wdg_test123',
      expect.objectContaining({
        config: expect.objectContaining({
          primaryColor: '#007bff',
          iconColor: '#ffffff',
          defaultTheme: 'light',
          position: 'bottom-right',
          allowedDomains: ['example.com'],
        }),
      })
    )
    // No data sources selected -> no widget-specific prompt is created
    expect(generateWidgetPrompt).not.toHaveBeenCalled()

    const emitted = wrapper.emitted('created')
    expect(emitted).toBeTruthy()
    expect((emitted![0][0] as Widget).widgetId).toBe('wdg_test123')
  })

  it('should not emit created when widget creation fails', async () => {
    const { quickCreateWidget } = await import('@/services/api/widgetsApi')
    vi.mocked(quickCreateWidget).mockRejectedValue(new Error('boom'))

    const wrapper = mountWizard()
    await fillStepOne(wrapper)
    await goToLastStep(wrapper)

    await wrapper.find('[data-testid="btn-create"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('created')).toBeFalsy()
  })
})
