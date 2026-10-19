import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AdminAttentionPanel from '@/components/admin/AdminAttentionPanel.vue'

const getFeaturesStatus = vi.hoisted(() => vi.fn())
const getStatus = vi.hoisted(() => vi.fn())
const setModelsNeedingAttention = vi.hoisted(() => vi.fn())
const setFeatureStatusCounts = vi.hoisted(() => vi.fn())
vi.mock('@/services/featuresService', () => ({ getFeaturesStatus }))
vi.mock('@/services/api/adminModelStatusApi', () => ({ modelStatusApi: { getStatus } }))
vi.mock('@/composables/useNavItems', () => ({
  setModelsNeedingAttention,
  setFeatureStatusCounts,
}))

const feature = (id: string, enabled: boolean, status: string) => ({
  id,
  name: id.toUpperCase(),
  enabled,
  status,
})

const service = (id: string, state: string, effect: string) => ({
  id,
  name: id,
  state,
  unlocks: '',
  effect,
  recovery: null,
  since: null,
  percent: null,
  model: null,
  minutes_left: null,
  profile: null,
  docs_anchor: null,
})

async function mountPanel() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  const wrapper = mount(AdminAttentionPanel, {
    props: { totalUsers: 12, signups: 3 },
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('AdminAttentionPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getFeaturesStatus.mockResolvedValue({
      features: {
        tika: feature('tika', true, 'healthy'),
        brave: feature('brave', false, 'disabled'),
      },
      modules: [],
    })
    getStatus.mockResolvedValue({ summary: { needsAttention: 0 } })
  })

  it('shows the four counters and links each to its page', async () => {
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-testid="card-admin-people"]').text()).toContain('12')
    expect(wrapper.get('[data-testid="card-admin-signups"]').text()).toContain('3')
    expect(wrapper.get('[data-testid="card-admin-features"]').text()).toContain('1')
    expect(wrapper.get('[data-testid="card-admin-models"]').attributes('href')).toBe(
      '/admin/setup?tab=health'
    )
    expect(wrapper.find('[data-testid="attention-all-good"]').exists()).toBe(true)
  })

  it('lists each problem with a link that fixes it', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: { tika: feature('tika', true, 'unhealthy') },
      modules: [{ id: 'mail', label_key: 'missing.key', state: 'needs_setup' }],
    })
    getStatus.mockResolvedValue({ summary: { needsAttention: 2 } })
    const wrapper = await mountPanel()

    const items = wrapper.findAll('[data-testid="item-needs-attention"]')
    expect(items).toHaveLength(3)
    expect(items[0]!.get('a').attributes('href')).toBe('/admin/setup?tab=health')
    expect(items[1]!.text()).toContain('TIKA')
    expect(items[2]!.text()).toContain('mail')
    expect(setModelsNeedingAttention).toHaveBeenCalledWith(2)
  })

  it('names a sidecar once even when its module also needs setup', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: { 'office-convert': feature('office-convert', true, 'unhealthy') },
      modules: [{ id: 'office_convert', label_key: 'missing.key', state: 'needs_setup' }],
    })
    const wrapper = await mountPanel()

    expect(wrapper.findAll('[data-testid="item-needs-attention"]')).toHaveLength(1)
  })

  it('lists a stopped or failed service once and never a starting one', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: { 'memory-service': feature('memory-service', true, 'unhealthy') },
      modules: [{ id: 'local_ai', label_key: 'missing.key', state: 'needs_setup' }],
      services: [
        service('qdrant', 'unavailable', 'Memories are paused.'),
        service('ollama', 'failed', 'Local AI models are not available.'),
        service('tts', 'starting', 'Spoken answers are off.'),
        service('docling', 'off', 'Complex documents are read as plain text.'),
      ],
    })
    const wrapper = await mountPanel()

    const items = wrapper.findAll('[data-testid="item-needs-attention"]')
    expect(items.map((item) => item.text())).toEqual([
      expect.stringContaining('Qdrant (memories) is not reachable. Memories are paused.'),
      expect.stringContaining(
        'Ollama (local AI) did not finish starting. Local AI models are not available.'
      ),
    ])
    expect(items[0]!.get('a').attributes('href')).toBe('/admin/features')
    expect(setFeatureStatusCounts).toHaveBeenCalledWith(
      expect.objectContaining({ services: expect.any(Array) })
    )
  })

  it('lists a stopped Piper service once, not also its provider feature', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: { piper: feature('piper', true, 'unhealthy') },
      modules: [{ id: 'piper_tts', label_key: 'missing.key', state: 'needs_setup' }],
      services: [service('tts', 'unavailable', 'Spoken answers are off.')],
    })
    const wrapper = await mountPanel()

    const items = wrapper.findAll('[data-testid="item-needs-attention"]')
    expect(items.map((item) => item.text())).toEqual([
      expect.stringContaining('Piper (spoken answers) is not reachable. Spoken answers are off.'),
    ])
  })

  it('does not flag a feature or module while its service is starting or downloading', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: {
        tika: feature('tika', true, 'unhealthy'),
        ollama: feature('ollama', true, 'unhealthy'),
      },
      modules: [{ id: 'local_ai', label_key: 'missing.key', state: 'needs_setup' }],
      services: [
        service('tika', 'starting', 'PDFs and Office files cannot be read.'),
        service('ollama', 'downloading', 'Local AI models are not available.'),
      ],
    })
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-testid="attention-all-good"]').exists()).toBe(true)
  })

  it('does not report a sidecar as broken when its container is switched off', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: { docling: feature('docling', true, 'unhealthy') },
      modules: [{ id: 'office_convert', label_key: 'missing.key', state: 'needs_setup' }],
      services: [
        service('docling', 'off', 'Complex documents are read as plain text.'),
        service('collabora', 'off', 'Office files open read-only.'),
      ],
    })
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-testid="attention-all-good"]').exists()).toBe(true)
  })

  it('offers a retry when the status cannot be loaded', async () => {
    getStatus.mockRejectedValueOnce(new Error('down'))
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-testid="attention-load-error"]').exists()).toBe(true)
    await wrapper.get('[data-testid="btn-attention-retry"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="attention-all-good"]').exists()).toBe(true)
  })
})
