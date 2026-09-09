import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderKnowledge from '@/components/assistants/BuilderKnowledge.vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPromptFiles: vi.fn().mockResolvedValue([]),
    uploadPromptFile: vi.fn(),
    deletePromptFile: vi.fn(),
  },
}))

vi.mock('@/services/filesService', () => ({
  getFileGroups: vi.fn().mockResolvedValue([{ name: 'legal', count: 2 }]),
}))

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listSharedWithMe: vi.fn().mockResolvedValue([
      { id: '9:shared-legal', name: 'Legal contracts', permission: 'use', ownerName: 'Ada' },
      { id: '9:private', name: 'Private', permission: 'read', ownerName: 'Ada' },
    ]),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 4 } }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn().mockResolvedValue(false) }),
}))

function mountKnowledge() {
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
  const wrapper = mount(BuilderKnowledge, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderKnowledge', () => {
  it('lists only folders the owner may use', async () => {
    const { wrapper } = mountKnowledge()
    await wrapper.vm.$nextTick()
    await Promise.resolve()
    await Promise.resolve()
    await wrapper.vm.$nextTick()
    const options = wrapper.findAll('[data-testid="select-shared-folder"] option')
    const values = options.map((option) => (option.element as HTMLOptionElement).value)
    expect(values).toContain('4:legal')
    expect(values).toContain('9:shared-legal')
    expect(values).not.toContain('9:private')
  })

  it('toggles includeUserFiles on the draft', async () => {
    const { wrapper, store } = mountKnowledge()
    await wrapper.get('[data-testid="chk-include-user-files"]').setValue(true)
    expect(store.current?.draft?.knowledge.includeUserFiles).toBe(true)
    expect(store.dirty).toBe(true)
  })
})
