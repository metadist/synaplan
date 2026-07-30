import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LocalAiDownloadCard from '@/components/setup/LocalAiDownloadCard.vue'
import type { LocalAiDownloadStatus } from '@/services/api/localAiStatusApi'

const getLocalAiDownloadStatus = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/localAiStatusApi', () => ({
  getLocalAiDownloadStatus,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAuthenticated: true, isAdmin: true }),
}))

const status = (overrides: Partial<LocalAiDownloadStatus> = {}): LocalAiDownloadStatus =>
  ({
    status: 'downloading',
    currentModel: 'bge-m3',
    percent: 43,
    message: null,
    models: [],
    updatedAt: null,
    ...overrides,
  }) as LocalAiDownloadStatus

// i18n comes from tests/unit/setup.ts, so these assertions exercise the real
// shipped en.json copy rather than a fixture.
const mountCard = () => mount(LocalAiDownloadCard, { global: { stubs: { Icon: true } } })

describe('LocalAiDownloadCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    getLocalAiDownloadStatus.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows progress while a model is downloading', async () => {
    getLocalAiDownloadStatus.mockResolvedValue(status())
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.find('[data-testid="local-ai-download-card"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('43%')
    expect(wrapper.text()).toContain('Cloud chat works now')
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('43')
  })

  it('stays hidden when nothing is downloading', async () => {
    getLocalAiDownloadStatus.mockResolvedValue(status({ status: 'idle', percent: null }))
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.find('[data-testid="local-ai-download-card"]').exists()).toBe(false)
  })

  it('stops polling once the download is ready', async () => {
    getLocalAiDownloadStatus.mockResolvedValue(status({ status: 'ready', percent: 100 }))
    mountCard()
    await flushPromises()
    expect(getLocalAiDownloadStatus).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(20000)
    expect(getLocalAiDownloadStatus).toHaveBeenCalledTimes(1)
  })

  it('surfaces a failed download without hiding that cloud chat works', async () => {
    getLocalAiDownloadStatus.mockResolvedValue(
      status({ status: 'error', percent: null, message: 'Download failed for bge-m3' })
    )
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.text()).toContain('Local AI download failed')
    expect(wrapper.text()).toContain('Download failed for bge-m3')
    expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
  })

  it('can be dismissed', async () => {
    getLocalAiDownloadStatus.mockResolvedValue(status())
    const wrapper = mountCard()
    await flushPromises()

    await wrapper.find('[data-testid="local-ai-download-dismiss"]').trigger('click')

    expect(wrapper.find('[data-testid="local-ai-download-card"]').exists()).toBe(false)
  })
})
