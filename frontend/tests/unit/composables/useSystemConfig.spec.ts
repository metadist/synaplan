import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import type { ConfigSchema } from '@/services/api/adminConfigApi'

/**
 * Copilot on #2257: the Live badge must describe the fields the admin can
 * actually save, and a runtime-config reload failure must not be reported as
 * a failed save.
 */

const getConfigSchema = vi.hoisted(() => vi.fn())
const getConfigValues = vi.hoisted(() => vi.fn())
const updateConfigValue = vi.hoisted(() => vi.fn())
const resetBrandingStyleApi = vi.hoisted(() => vi.fn())
const success = vi.hoisted(() => vi.fn())
const showError = vi.hoisted(() => vi.fn())
const reload = vi.hoisted(() => vi.fn())
const applyBrandingTheme = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/adminConfigApi', () => ({
  getConfigSchema,
  getConfigValues,
  resetBrandingStyle: resetBrandingStyleApi,
  updateConfigValue,
  testConnection: vi.fn(),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error: showError }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ reload }),
}))

vi.mock('@/utils/brandingTheme', () => ({
  applyBrandingTheme,
}))

import { useSystemConfig } from '@/composables/useSystemConfig'

const field = (tab: string, section: string, extra: Record<string, unknown> = {}) => ({
  tab,
  section,
  type: 'text' as const,
  sensitive: false,
  description: '',
  default: '',
  ...extra,
})

const schema: ConfigSchema = {
  tabs: {
    ai: {
      label: 'AI',
      sections: {
        cloud: { label: 'Cloud', fields: ['OPENAI_API_KEY', 'GOOGLE_VERTEX_ACCESS_TOKEN'] },
        routingish: { label: 'Routing', fields: ['FLAG_A', 'FLAG_B'] },
      },
    },
    features: {
      label: 'Features',
      sections: { people: { label: 'People', fields: ['FEATURE_X'] } },
    },
  },
  fields: {
    OPENAI_API_KEY: field('ai', 'cloud', {
      type: 'password',
      sensitive: true,
      source: 'database',
      managedBy: 'ai-infrastructure',
    }),
    GOOGLE_VERTEX_ACCESS_TOKEN: field('ai', 'cloud', { type: 'password', sensitive: true }),
    FLAG_A: field('ai', 'routingish', { type: 'boolean', source: 'database' }),
    FLAG_B: field('ai', 'routingish', { type: 'boolean' }),
    FEATURE_X: field('features', 'people', { type: 'boolean', source: 'database' }),
  },
}

function mountConfig() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
      en: {
        admin: {
          config: {
            saved: 'Saved',
            savedLive: 'Saved live',
            saveError: 'Failed to save configuration',
            savedButNotRefreshed: 'Saved, but the menus could not be refreshed.',
            brandingReset: {
              success: 'Style reset',
              partial: '{reset} reset, {failed} failed',
              failed: 'Reset failed',
              unconfirmed: 'Could not confirm the reset',
            },
          },
        },
      },
    },
  })
  const Harness = defineComponent({
    setup() {
      return { config: useSystemConfig() }
    },
    template: '<div />',
  })
  return mount(Harness, { global: { plugins: [i18n] } })
}

describe('useSystemConfig', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getConfigSchema.mockResolvedValue(schema)
    getConfigValues.mockResolvedValue({})
    reload.mockResolvedValue(undefined)
  })

  it('badges a section Live only when every editable field is stored in the database', async () => {
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const cloud = wrapper.vm.config.resolveSection(
      { tab: 'ai', section: 'cloud' },
      { hideManaged: true }
    )
    expect(cloud?.fields.map((f) => f.key)).toEqual(['GOOGLE_VERTEX_ACCESS_TOKEN'])
    expect(cloud?.isLive).toBe(false)

    const mixed = wrapper.vm.config.resolveSection({ tab: 'ai', section: 'routingish' })
    expect(mixed?.isLive).toBe(false)

    const live = wrapper.vm.config.resolveSection({ tab: 'features', section: 'people' })
    expect(live?.isLive).toBe(true)
  })

  it('keeps a saved feature flag when refreshing the menus fails', async () => {
    updateConfigValue.mockResolvedValue({ success: true, requiresRestart: false })
    reload.mockRejectedValue(new Error('runtime config down'))
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    await wrapper.vm.config.update('FEATURE_X', 'true')
    await flushPromises()

    expect(success).toHaveBeenCalledWith('Saved live')
    expect(showError).toHaveBeenCalledWith('Saved, but the menus could not be refreshed.')
    expect(showError).not.toHaveBeenCalledWith('Failed to save configuration')
    // Nested inside the harness return, so the ref is not auto-unwrapped.
    expect(wrapper.vm.config.values.value.FEATURE_X.isSet).toBe(true)
  })

  it('resets the style with one outcome and applies the defaults live', async () => {
    resetBrandingStyleApi.mockResolvedValue({
      success: true,
      reset: ['BRAND_PRIMARY_COLOR'],
      failed: [],
      requiresRestart: false,
    })
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const ok = await wrapper.vm.config.resetBrandingStyle()
    await flushPromises()

    expect(ok).toBe(true)
    expect(success).toHaveBeenCalledWith('Style reset')
    expect(reload).toHaveBeenCalledTimes(1)
    expect(applyBrandingTheme).toHaveBeenCalledTimes(1)
  })

  it('says what did and did not reset on a partial failure', async () => {
    resetBrandingStyleApi.mockResolvedValue({
      success: false,
      reset: ['BRAND_PRIMARY_COLOR'],
      failed: ['BRAND_FONT_FAMILY'],
      requiresRestart: false,
    })
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const ok = await wrapper.vm.config.resetBrandingStyle()
    await flushPromises()

    expect(ok).toBe(false)
    expect(showError).toHaveBeenCalledWith('1 reset, 1 failed')
    expect(reload).toHaveBeenCalledTimes(1)
    expect(applyBrandingTheme).toHaveBeenCalledTimes(1)
  })

  it('re-reads the style when the reset response is lost and reports what landed', async () => {
    getConfigValues
      .mockResolvedValueOnce({
        BRAND_PRIMARY_COLOR: { value: '#ff00aa', isSet: true, isMasked: false },
      })
      .mockResolvedValueOnce({
        BRAND_PRIMARY_COLOR: { value: '', isSet: false, isMasked: false },
      })
    resetBrandingStyleApi.mockRejectedValue(new Error('response lost'))
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const ok = await wrapper.vm.config.resetBrandingStyle()
    await flushPromises()

    expect(ok).toBe(true)
    expect(success).toHaveBeenCalledWith('Style reset')
    expect(showError).not.toHaveBeenCalledWith('Reset failed')
    expect(applyBrandingTheme).toHaveBeenCalledTimes(1)
    expect(wrapper.vm.config.values.value.BRAND_PRIMARY_COLOR.isSet).toBe(false)
  })

  it('says the values are unchanged only after a re-read confirms it', async () => {
    const same = { BRAND_PRIMARY_COLOR: { value: '#ff00aa', isSet: true, isMasked: false } }
    getConfigValues.mockResolvedValue(same)
    resetBrandingStyleApi.mockRejectedValue(new Error('network'))
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const ok = await wrapper.vm.config.resetBrandingStyle()
    await flushPromises()

    expect(ok).toBe(false)
    expect(showError).toHaveBeenCalledWith('Reset failed')
    expect(showError).not.toHaveBeenCalledWith('Could not confirm the reset')
  })

  it('does not claim the style is unchanged when the re-read also fails', async () => {
    getConfigValues.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('config down'))
    resetBrandingStyleApi.mockRejectedValue(new Error('response lost'))
    const wrapper = mountConfig()
    await wrapper.vm.config.load()

    const ok = await wrapper.vm.config.resetBrandingStyle()
    await flushPromises()

    expect(ok).toBe(false)
    expect(showError).toHaveBeenCalledWith('Could not confirm the reset')
    expect(showError).not.toHaveBeenCalledWith('Reset failed')
  })
})
