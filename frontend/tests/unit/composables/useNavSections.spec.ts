import { defineComponent } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useNavSections } from '@/composables/useNavSections'
import { useAuthStore, type User } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'

function mountSections(user: { email: string; level: string } | null) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = user
    ? ({
        id: 1,
        email: user.email,
        level: user.level,
        isAdmin: user.level === 'ADMIN',
      } as User)
    : null

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })

  const Harness = defineComponent({
    setup() {
      return useNavSections()
    },
    template: '<div />',
  })

  return mount(Harness, { global: { plugins: [router] } })
}

describe('useNavSections', () => {
  it('gives guests only Chats', () => {
    const wrapper = mountSections(null)
    const keys = wrapper.vm.sections.map((section: { key: string }) => section.key)
    expect(keys).toEqual(['chats'])
  })

  it('splits signed-in navigation into Chats, Library, Assistants and Channels', () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    const keys = wrapper.vm.sections.map((section: { key: string }) => section.key)
    expect(keys).toEqual(['chats', 'library', 'assistants', 'channels'])
  })

  it('adds Operate for admins', () => {
    const wrapper = mountSections({ email: 'admin@test.com', level: 'ADMIN' })
    const keys = wrapper.vm.sections.map((section: { key: string }) => section.key)
    expect(keys).toContain('operate')
  })

  it('does not treat /channels as a chat route', () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    expect(wrapper.vm.sectionKeyForPath('/')).toBe('chats')
    expect(wrapper.vm.sectionKeyForPath('/chats/incoming')).toBe('chats')
    expect(wrapper.vm.sectionKeyForPath('/files/search')).toBe('library')
    expect(wrapper.vm.sectionKeyForPath('/channels')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/channels/widgets')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/ai/models')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/ai/routing')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/ai/assistants')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/ai/assistants/4')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/ai/instructions')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/channels/tasks')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/channels/approvals')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/admin/people')).toBe('operate')
    expect(wrapper.vm.sectionKeyForPath('/profile')).toBeNull()
  })

  it('puts the apps under Apps and tasks, approvals and shortcuts under Assistants', () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    expect(wrapper.vm.sectionKeyForPath('/apps')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/apps/connected')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/apps/telegram')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/plugins/fastbill')).toBe('channels')
    expect(wrapper.vm.sectionKeyForPath('/tasks')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/approvals')).toBe('assistants')
    expect(wrapper.vm.sectionKeyForPath('/prompts')).toBe('assistants')
  })

  it('selects no rail section on profile pages and remembers the previous one', async () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    const router = wrapper.vm.$router

    await router.push('/files')
    await router.isReady()
    expect(wrapper.vm.activeKey).toBe('library')

    await router.push('/settings/profile')
    await router.isReady()
    expect(wrapper.vm.activeKey).toBeNull()

    await router.push('/memories')
    await router.isReady()
    expect(wrapper.vm.activeKey).toBeNull()

    await router.push('/not-a-section')
    await router.isReady()
    expect(wrapper.vm.activeKey).toBe('library')
  })

  it('opens a new chat instead of the last thread when Chats is chosen from another page', async () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    const findOrCreate = vi.spyOn(useChatsStore(), 'findOrCreateEmptyChat').mockResolvedValue(null)
    const router = wrapper.vm.$router

    await router.push('/files')
    await router.isReady()
    await wrapper.vm.selectSection('chats')
    await router.isReady()

    expect(findOrCreate).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('opens a new chat when Chats is clicked while a conversation is already open', async () => {
    const wrapper = mountSections({ email: 'user@test.com', level: 'PRO' })
    const findOrCreate = vi.spyOn(useChatsStore(), 'findOrCreateEmptyChat').mockResolvedValue(null)
    const router = wrapper.vm.$router

    await router.push('/')
    await router.isReady()
    await wrapper.vm.selectSection('chats')

    expect(findOrCreate).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('sends a guest to the chat page without creating a chat', async () => {
    const wrapper = mountSections(null)
    const findOrCreate = vi.spyOn(useChatsStore(), 'findOrCreateEmptyChat').mockResolvedValue(null)
    const router = wrapper.vm.$router

    await router.push('/files')
    await router.isReady()
    await wrapper.vm.selectSection('chats')
    await router.isReady()

    expect(findOrCreate).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/')
  })
})
