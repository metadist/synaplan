import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { httpClient } = vi.hoisted(() => ({ httpClient: vi.fn() }))
vi.mock('@/services/api/httpClient', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/api/httpClient')>()),
  httpClient,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAuthenticated: true }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const dialog = vi.hoisted(() => ({ confirm: vi.fn(async () => true) }))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => dialog }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => notify,
}))

import PromptsView from '@/views/PromptsView.vue'
import { useCommandsStore } from '@/stores/commands'

interface Row {
  id: number
  name: string
  command: string
  body: string
  tags: string[]
}

let rows: Row[] = []

function route(endpoint: string, options: { method?: string; body?: string } = {}) {
  const method = options.method ?? 'GET'
  if (method === 'GET' && endpoint === '/api/v1/saved-prompts') {
    return Promise.resolve({ success: true, prompts: rows.map((row) => ({ ...row })) })
  }
  if (method === 'POST' && endpoint === '/api/v1/saved-prompts') {
    const payload = JSON.parse(options.body ?? '{}') as Partial<Omit<Row, 'id'>>
    const created: Row = { id: 99, name: '', command: '', body: '', tags: [], ...payload }
    rows = [...rows, created]
    return Promise.resolve({ success: true, prompt: created })
  }
  const match = endpoint.match(/^\/api\/v1\/saved-prompts\/(\d+)$/)
  if (match && method === 'PUT') {
    const id = Number(match[1])
    const payload = JSON.parse(options.body ?? '{}') as Omit<Row, 'id'>
    rows = rows.map((row) => (row.id === id ? { ...row, ...payload } : row))
    return Promise.resolve({ success: true, prompt: rows.find((row) => row.id === id) })
  }
  if (match && method === 'DELETE') {
    rows = rows.filter((row) => row.id !== Number(match[1]))
    return Promise.resolve({ success: true })
  }
  return Promise.reject(new Error(`unexpected ${method} ${endpoint}`))
}

const mountView = async () => {
  const wrapper = mount(PromptsView, {
    global: {
      stubs: { MainLayout: { template: '<div><slot /></div>' } },
    },
  })
  await flushPromises()
  return wrapper
}

const calls = (method: string) =>
  httpClient.mock.calls.filter(([, options]) => (options?.method ?? 'GET') === method)

describe('PromptsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    httpClient.mockReset()
    httpClient.mockImplementation(route)
    notify.success.mockReset()
    notify.error.mockReset()
    rows = [{ id: 7, name: 'Note', command: 'notiz', body: 'Write a note', tags: ['work'] }]
  })

  it('labels the submit button as a save action, not as a finished save', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-prompt-new"]').trigger('click')

    const submit = wrapper.find('[data-testid="btn-saved-prompt-save"]')
    expect(submit.text()).toBe('Save shortcut')
    expect(submit.text()).not.toBe('Shortcut saved.')
  })

  it('saves an existing row with PUT and the edited fields, never with POST', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-saved-prompt-edit"]').trigger('click')

    const form = wrapper.find('[data-testid="form-saved-prompt"]')
    expect(
      (wrapper.find('[data-testid="input-saved-prompt-command"]').element as HTMLInputElement).value
    ).toBe('notiz')
    expect(wrapper.find('[data-testid="text-saved-prompt-form-title"]').text()).toBe(
      'Edit shortcut'
    )

    await wrapper.find('[data-testid="input-saved-prompt-name"]').setValue('Note 2')
    await wrapper.find('[data-testid="input-saved-prompt-command"]').setValue('notiz2')
    await wrapper.find('[data-testid="input-saved-prompt-body"]').setValue('Write a better note')
    await form.trigger('submit')
    await flushPromises()

    expect(calls('POST')).toHaveLength(0)
    const puts = calls('PUT')
    expect(puts).toHaveLength(1)
    expect(puts[0][0]).toBe('/api/v1/saved-prompts/7')
    expect(JSON.parse(puts[0][1].body)).toEqual({
      name: 'Note 2',
      command: 'notiz2',
      body: 'Write a better note',
      tags: ['work'],
    })
    expect(notify.success).toHaveBeenCalledWith('Shortcut saved.')
    // The stored row comes back on the write; a second request could only fail it.
    expect(calls('GET')).toHaveLength(1)
  })

  it('shows the new name and command after an update and lists the command for /', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-saved-prompt-edit"]').trigger('click')
    await wrapper.find('[data-testid="input-saved-prompt-command"]').setValue('notiz2')
    await wrapper.find('[data-testid="input-saved-prompt-name"]').setValue('Note 2')
    await wrapper.find('[data-testid="form-saved-prompt"]').trigger('submit')
    await flushPromises()

    const row = wrapper.find('[data-testid="row-saved-prompt"]')
    expect(row.text()).toContain('Note 2')
    expect(row.text()).toContain('/notiz2')
    expect(wrapper.find('[data-testid="form-saved-prompt"]').exists()).toBe(false)

    const commands = useCommandsStore()
    expect(commands.getCommand('notiz2')?.promptBody).toBe('Write a note')
    expect(commands.getCommand('notiz')).toBeUndefined()
  })

  it('creates a new prompt with POST', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-prompt-new"]').trigger('click')
    expect(wrapper.find('[data-testid="text-saved-prompt-form-title"]').text()).toBe('New shortcut')

    await wrapper.find('[data-testid="input-saved-prompt-name"]').setValue('Second')
    await wrapper.find('[data-testid="input-saved-prompt-command"]').setValue('second')
    await wrapper.find('[data-testid="input-saved-prompt-body"]').setValue('Second text')
    await wrapper.find('[data-testid="form-saved-prompt"]').trigger('submit')
    await flushPromises()

    expect(calls('PUT')).toHaveLength(0)
    expect(calls('POST')).toHaveLength(1)
    expect(JSON.parse(calls('POST')[0][1].body)).toEqual({
      name: 'Second',
      command: 'second',
      body: 'Second text',
    })
    expect(useCommandsStore().getCommand('second')?.promptBody).toBe('Second text')
  })

  it('keeps the form open with the reason when the update is rejected', async () => {
    httpClient.mockImplementation((endpoint: string, options: { method?: string } = {}) =>
      options.method === 'PUT'
        ? Promise.reject(new Error('You already have a prompt with that command.'))
        : route(endpoint, options)
    )
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-saved-prompt-edit"]').trigger('click')
    await wrapper.find('[data-testid="form-saved-prompt"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-testid="form-saved-prompt"]').text()).toContain(
      'You already have a prompt with that command.'
    )
    expect(notify.success).not.toHaveBeenCalled()
  })

  it('removes a deleted prompt from the slash commands', async () => {
    const wrapper = await mountView()
    expect(useCommandsStore().getCommand('notiz')).toBeDefined()

    await wrapper.find('[data-testid="btn-saved-prompt-delete"]').trigger('click')
    await flushPromises()

    expect(calls('DELETE')).toHaveLength(1)
    expect(calls('GET')).toHaveLength(1)
    expect(wrapper.find('[data-testid="row-saved-prompt"]').exists()).toBe(false)
    expect(useCommandsStore().getCommand('notiz')).toBeUndefined()
  })

  it('keeps the prompt and says so when the delete fails', async () => {
    httpClient.mockImplementation((endpoint: string, options: { method?: string } = {}) =>
      options.method === 'DELETE' ? Promise.reject(new Error('HTTP 500')) : route(endpoint, options)
    )
    const wrapper = await mountView()

    await wrapper.find('[data-testid="btn-saved-prompt-delete"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith(
      'The shortcut could not be deleted. It is still in your list.'
    )
    expect(wrapper.find('[data-testid="row-saved-prompt"]').exists()).toBe(true)
    expect(useCommandsStore().getCommand('notiz')).toBeDefined()
  })

  it('asks before deleting and keeps the shortcut when the person cancels', async () => {
    dialog.confirm.mockResolvedValueOnce(false)
    const wrapper = await mountView()

    await wrapper.find('[data-testid="btn-saved-prompt-delete"]').trigger('click')
    await flushPromises()

    expect(dialog.confirm).toHaveBeenCalled()
    expect(calls('DELETE')).toHaveLength(0)
    expect(wrapper.find('[data-testid="row-saved-prompt"]').exists()).toBe(true)
  })

  it('lists a new prompt in name order without reloading the list', async () => {
    rows = [
      { id: 1, name: 'Alpha', command: 'alpha', body: 'A', tags: [] },
      { id: 2, name: 'Gamma', command: 'gamma', body: 'G', tags: [] },
    ]
    const wrapper = await mountView()
    await wrapper.find('[data-testid="btn-prompt-new"]').trigger('click')
    await wrapper.find('[data-testid="input-saved-prompt-name"]').setValue('Beta')
    await wrapper.find('[data-testid="input-saved-prompt-command"]').setValue('beta')
    await wrapper.find('[data-testid="input-saved-prompt-body"]').setValue('B')
    await wrapper.find('[data-testid="form-saved-prompt"]').trigger('submit')
    await flushPromises()

    const names = wrapper.findAll('[data-testid="row-saved-prompt"]').map((row) => row.text())
    expect(names.map((text) => text.split('/')[0].trim())).toEqual(['Alpha', 'Beta', 'Gamma'])
    expect(calls('GET')).toHaveLength(1)
  })
})
