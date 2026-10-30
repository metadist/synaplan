import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import AiCreatedShortcut from '@/components/files/AiCreatedShortcut.vue'
import filesService from '@/services/filesService'

vi.mock('@/services/filesService', () => ({
  default: {
    getFacets: vi.fn(),
    listFiles: vi.fn(),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost',
}))

vi.mock('@/services/api/mediaAuth', () => ({
  useMediaSrc: () => ({ mediaSrc: (url: string) => url }),
}))

const getFacets = vi.mocked(filesService.getFacets)
const listFiles = vi.mocked(filesService.listFiles)

async function mountCard() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/files/generated', component: { template: '<div />' } }],
  })
  const wrapper = mount(AiCreatedShortcut, {
    global: {
      plugins: [router],
      mocks: { $t: (key: string) => key },
    },
  })
  await router.isReady()
  await flushPromises()
  return wrapper
}

describe('AiCreatedShortcut', () => {
  beforeEach(() => {
    getFacets.mockReset()
    listFiles.mockReset()
    listFiles.mockResolvedValue({
      files: [],
      pagination: { page: 1, limit: 3, total: 0, pages: 0 },
    } as never)
  })

  it('stays hidden when there are no AI-created files', async () => {
    getFacets.mockResolvedValue({
      source: { generated: 0, compute: 0 },
      vector_state: {},
      incoming: 0,
      total: 0,
    })
    const wrapper = await mountCard()
    expect(wrapper.find('[data-testid="card-files-ai-created"]').exists()).toBe(false)
  })

  it('shows one icon when the latest files have no picture', async () => {
    getFacets.mockResolvedValue({
      source: { generated: 3, compute: 0 },
      vector_state: {},
      incoming: 0,
      total: 3,
    })
    listFiles.mockResolvedValue({
      files: [
        { id: 1, filename: 'a.md', file_type: 'md', file_size: 1 },
        { id: 2, filename: 'b.md', file_type: 'md', file_size: 1 },
        { id: 3, filename: 'c.md', file_type: 'md', file_size: 1 },
      ],
      pagination: { page: 1, limit: 3, total: 3, pages: 1 },
    } as never)
    const wrapper = await mountCard()
    expect(wrapper.findAll('svg')).toHaveLength(1)
    expect(wrapper.findAll('img')).toHaveLength(0)
  })

  it('links to the gallery when AI-created files exist', async () => {
    getFacets.mockResolvedValue({
      source: { generated: 2, compute: 1 },
      vector_state: {},
      incoming: 0,
      total: 3,
    })
    const wrapper = await mountCard()
    const card = wrapper.get('[data-testid="card-files-ai-created"]')
    expect(card.text()).toContain('files.aiCreated.title')
    expect(card.attributes('href')).toBe('/files/generated')
  })
})
