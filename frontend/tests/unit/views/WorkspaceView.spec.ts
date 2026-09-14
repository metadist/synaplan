import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const getConfigSync = vi.fn()
const getWorkspace = vi.fn()
const listWorkspaceFiles = vi.fn()
const loadWorkspaceFileBlob = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

vi.mock('@/services/computeWorkspaceService', () => ({
  getWorkspace: () => getWorkspace(),
  listWorkspaceFiles: (...args: unknown[]) => listWorkspaceFiles(...args),
  loadWorkspaceFileBlob: (...args: unknown[]) => loadWorkspaceFileBlob(...args),
  downloadWorkspaceFile: vi.fn(),
  deleteWorkspace: vi.fn(),
  workspaceFileName: (path: string) => path.split('/').pop() ?? path,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn(), prompt: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import WorkspaceView from '@/views/WorkspaceView.vue'

function mountView() {
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/files', component: { template: '<div />' } },
      { path: '/files/workspace', component: WorkspaceView },
    ],
  })
  return mount(WorkspaceView, {
    attachTo: document.body,
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        FilesTabs: { template: '<nav />' },
      },
    },
  })
}

const info = { exists: true, usedMb: 1, quotaMb: 256 }
const csv = { path: 'report.csv', size: 12, mime: 'text/csv', modifiedAt: '' }

describe('WorkspaceView', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
    getConfigSync.mockReturnValue({ features: { computeWorkspacesEnabled: true } })
    getWorkspace.mockReset()
    listWorkspaceFiles.mockReset()
    loadWorkspaceFileBlob.mockReset()
  })

  it('shows the error with a retry instead of the empty state when loading fails', async () => {
    getWorkspace.mockRejectedValueOnce(new Error('503'))
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="workspace-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workspace-empty"]').exists()).toBe(false)

    getWorkspace.mockResolvedValueOnce({ ...info, exists: false })
    await wrapper.find('[data-testid="btn-workspace-retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="workspace-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="workspace-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('opens the preview as a dialog and closes it on Escape', async () => {
    getWorkspace.mockResolvedValue(info)
    listWorkspaceFiles.mockResolvedValue([csv])
    loadWorkspaceFileBlob.mockResolvedValue(new Blob(['a,b'], { type: 'text/csv' }))
    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('[data-testid="btn-workspace-preview"]').trigger('click')
    await flushPromises()

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-labelledby')).toBe('workspace-preview-title')
    expect(document.activeElement?.getAttribute('data-testid')).toBe('btn-workspace-preview-close')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()

    expect(wrapper.find('[data-testid="workspace-preview"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
