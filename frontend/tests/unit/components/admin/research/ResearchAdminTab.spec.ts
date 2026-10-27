import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ResearchAdminTab from '@/components/admin/research/ResearchAdminTab.vue'

const fetchResearchAdmin = vi.fn()
const saveResearchAdmin = vi.fn()
const startResearchSample = vi.fn()

vi.mock('@/services/api/researchAdminApi', () => ({
  fetchResearchAdmin: (...args: unknown[]) => fetchResearchAdmin(...args),
  saveResearchAdmin: (...args: unknown[]) => saveResearchAdmin(...args),
  startResearchSample: (...args: unknown[]) => startResearchSample(...args),
}))

const off = {
  enabled: false,
  envLocked: false,
  readingModelId: null,
  checkingModelId: null,
  models: [{ id: 3, name: 'Atlas', available: true, reason: null }],
  limits: { maxActiveRuns: 5, maxActiveThorough: 1 },
  budgets: {
    quick: { searches: 2, sources: 4, minutes: 1, costCap: 0.1 },
    standard: { searches: 10, sources: 15, minutes: 6, costCap: 1 },
    thorough: { searches: 30, sources: 40, minutes: 20, costCap: 5 },
  },
  sample: null,
}

describe('ResearchAdminTab', () => {
  beforeEach(() => {
    fetchResearchAdmin.mockReset()
    saveResearchAdmin.mockReset()
    startResearchSample.mockReset()
    fetchResearchAdmin.mockResolvedValue(off)
  })

  it('says research is off and turns it on', async () => {
    saveResearchAdmin.mockResolvedValue({ ...off, enabled: true })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.get('[data-testid="research-admin-status"]').text()).toContain('Research is off')
    expect(wrapper.get('[data-testid="research-admin-sample"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="research-admin-turn-on"]').trigger('click')
    await flushPromises()

    expect(saveResearchAdmin).toHaveBeenCalledWith({ enabled: true })
    expect(wrapper.text()).toContain('People can no longer start research')
  })

  it('does not start a sample until the cost is accepted', async () => {
    fetchResearchAdmin.mockResolvedValue({ ...off, enabled: true })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    const button = wrapper.get('[data-testid="research-admin-sample"]')
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="research-admin-sample-confirm"]').setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()
    expect(startResearchSample).not.toHaveBeenCalled()
  })
})
