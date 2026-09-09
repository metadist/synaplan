import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ExportImportPanel from '@/components/settings/ExportImportPanel.vue'
import en from '@/i18n/en.json'

const sections = vi.fn()
const preview = vi.fn()
const importBundle = vi.fn()
const exportBundle = vi.fn()

vi.mock('@/composables/useBundleFeature', () => ({
  isBundleEnabled: () => true,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/services/api/bundleApi', () => ({
  bundleApi: {
    sections: (...args: unknown[]) => sections(...args),
    preview: (...args: unknown[]) => preview(...args),
    import: (...args: unknown[]) => importBundle(...args),
    export: (...args: unknown[]) => exportBundle(...args),
  },
}))

function mountPanel() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ExportImportPanel, { global: { plugins: [i18n] } })
}

describe('ExportImportPanel', () => {
  it('renders the checklist and sends the conflict choice', async () => {
    sections.mockResolvedValue([
      { kind: 'agents', version: 1, dependsOn: ['prompts'], itemCount: 1 },
    ])
    preview.mockResolvedValue({
      success: true,
      fromOtherInstance: true,
      sections: [
        {
          kind: 'agents',
          itemCount: 1,
          items: [{ code: 'needsModel', itemKey: 'contract-review', detail: 'chat' }],
        },
      ],
    })
    importBundle.mockResolvedValue({
      success: true,
      createsDrafts: true,
      results: [{ kind: 'agents', created: ['contract-review'], skipped: [], failed: [] }],
    })

    const wrapper = mountPanel()
    await wrapper.vm.$nextTick()
    await Promise.resolve()
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="section-export-import"]').text()).toContain(
      'never copies shares'
    )

    const file = new File(
      [JSON.stringify({ schema: 'synaplan-bundle.v1', sections: [] })],
      'export.json',
      { type: 'application/json' }
    )
    const input = wrapper.get('[data-testid="input-bundle-file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await Promise.resolve()
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="bundle-preview"]').text()).toContain('Needs a model')

    await wrapper.get('[data-testid="input-bundle-overwrite"]').setValue(true)
    await wrapper.get('[data-testid="btn-bundle-import"]').trigger('click')
    await Promise.resolve()

    expect(importBundle).toHaveBeenCalledWith(expect.anything(), 'overwrite')
    expect(wrapper.get('[data-testid="bundle-results"]').text()).toContain('1 created')
  })
})
