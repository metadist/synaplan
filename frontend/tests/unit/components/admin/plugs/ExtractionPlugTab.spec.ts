import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ExtractionPlugTab from '@/components/admin/plugs/ExtractionPlugTab.vue'

const getExtractionStatus = vi.fn()
const saveExtractionChains = vi.fn()
const testExtraction = vi.fn()

vi.mock('@/services/api/adminPlugsApi', () => ({
  getExtractionStatus: (...args: unknown[]) => getExtractionStatus(...args),
  saveExtractionChains: (...args: unknown[]) => saveExtractionChains(...args),
  testExtraction: (...args: unknown[]) => testExtraction(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/services/api/adminConfigApi', () => ({
  testConnection: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
}))

describe('ExtractionPlugTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getExtractionStatus.mockResolvedValue({
      adapters: [
        {
          key: 'docling',
          label: 'Docling',
          docsUrl: '',
          sovereignty: 'self-hosted',
          health: { available: false, reason: 'down' },
        },
        {
          key: 'tika',
          label: 'Apache Tika',
          docsUrl: '',
          sovereignty: 'self-hosted',
          health: { available: true, reason: null },
        },
      ],
      chains: {
        document: ['tika', 'pdf_vision'],
        text: ['native'],
        image: ['vision'],
        audio: ['stt_cloud'],
        audio_no_cloud: ['whisper_local'],
        video: ['video_analysis'],
      },
      quality: { minLength: 10, minEntropy: 3, applyTo: ['pdf'] },
    })
  })

  it('shows the lead sentence, health pills and the test button', async () => {
    const wrapper = mount(ExtractionPlugTab, {
      global: {
        stubs: {
          Icon: true,
          RouterLink: { template: '<a><slot /></a>', props: ['to'] },
        },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Choose how documents are turned into text')
    expect(wrapper.get('[data-testid="extraction-health-docling"]').text()).toContain('Docling')
    expect(wrapper.get('[data-testid="extraction-health-docling"]').text()).toContain('down')
    expect(wrapper.get('[data-testid="extraction-test-docling"]').text()).toContain('Test Docling')
    expect(wrapper.get('[data-testid="extraction-test-tika"]').text()).toContain('Test Tika')
    expect(wrapper.get('[data-testid="extraction-sidecar-settings"]').text()).toContain(
      'Open Processing settings'
    )
    expect(wrapper.get('[data-testid="extraction-test-button"]').text()).toContain(
      'Test with a file'
    )
  })
})
