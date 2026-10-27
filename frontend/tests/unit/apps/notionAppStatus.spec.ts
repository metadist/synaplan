import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AppCard from '@/components/apps/AppCard.vue'
import { loadAttentionAppIds, loadConnectedAppIds } from '@/apps/status'
import type { AppDefinition } from '@/apps/catalog'

const { status } = vi.hoisted(() => ({ status: vi.fn() }))

vi.mock('@/services/api/notionAppApi', () => ({ notionAppApi: { status } }))

const notion: AppDefinition = {
  id: 'notion',
  category: 'files',
  icon: 'simple-icons:notion',
  available: () => true,
}

describe('Notion app tile status', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('counts a working connection as connected', async () => {
    status.mockResolvedValue({ status: 'connected' })
    expect(await loadConnectedAppIds([notion])).toEqual(new Set(['notion']))
    expect(await loadAttentionAppIds([notion])).toEqual(new Set())
  })

  it('flags an expired sign-in instead of calling it connected', async () => {
    status.mockResolvedValue({ status: 'reauth_required' })
    expect(await loadConnectedAppIds([notion])).toEqual(new Set())
    expect(await loadAttentionAppIds([notion])).toEqual(new Set(['notion']))
  })

  it('treats a failed request as unknown', async () => {
    status.mockRejectedValue(new Error('offline'))
    expect(await loadAttentionAppIds([notion])).toEqual(new Set())
  })

  it('shows the sign-in badge instead of the connected badge', () => {
    const wrapper = mount(AppCard, {
      props: {
        to: '/apps/notion',
        name: 'Notion',
        tagline: 'Pages',
        icon: 'x',
        connected: true,
        attention: true,
      },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    expect(wrapper.get('[data-testid="badge-app-attention"]').text()).toBe('Sign in again')
    expect(wrapper.find('[data-testid="badge-app-connected"]').exists()).toBe(false)
  })
})
