import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderToolsSkills from '@/components/assistants/BuilderToolsSkills.vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/services/api/mcpServersApi', () => ({
  mcpServersApi: { list: vi.fn().mockResolvedValue({ servers: [] }) },
}))

function mountTools() {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  store.current = {
    id: 3,
    slug: 'contract-review',
    name: 'Contract review',
    description: null,
    icon: '',
    status: 'draft',
    promptId: 9,
    parentId: null,
    source: 'manual',
    routable: false,
    publishedVersionId: null,
    draft: emptyAgentDraft(),
    createdAt: 1,
    updatedAt: 1,
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BuilderToolsSkills, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderToolsSkills', () => {
  it('turns internet off on the draft', async () => {
    const { wrapper, store } = mountTools()
    await wrapper.get('[data-testid="chk-tool-internet"]').setValue(false)
    expect(store.current?.draft?.tools.internet).toBe(false)
    expect(store.dirty).toBe(true)
  })

  it('denies a skill when the chip is toggled off', async () => {
    const { wrapper, store } = mountTools()
    await wrapper.get('[data-testid="chip-skill-email_me"]').trigger('click')
    expect(store.current?.draft?.skills.deny).toContain('email_me')
  })
})
