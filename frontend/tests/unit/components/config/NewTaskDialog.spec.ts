import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import NewTaskDialog from '@/components/config/NewTaskDialog.vue'

const { createPrompt, deletePrompt, createTask, updateTask, success, error, ensureTz } = vi.hoisted(
  () => ({
    createPrompt: vi.fn(),
    deletePrompt: vi.fn(),
    createTask: vi.fn(),
    updateTask: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    ensureTz: vi.fn(),
  })
)

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: { createPrompt, deletePrompt },
}))

vi.mock('@/services/api/savedTasksApi', () => ({
  savedTasksApi: { create: createTask, update: updateTask },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))

vi.mock('@/composables/useAccountTimezone', () => ({
  ensureAccountTimezone: () => ensureTz(),
}))

vi.mock('@/composables/useFullscreenTeleportTarget', () => ({
  useFullscreenTeleportTarget: () => ({ teleportTarget: 'body' }),
}))

const task = { id: 5, promptId: 11, name: 'Morning news' }

const mountOpen = async () => {
  const wrapper = mount(NewTaskDialog, {
    props: { open: false },
    attachTo: document.body,
    global: { stubs: { Teleport: true } },
  })
  await wrapper.setProps({ open: true })
  return wrapper
}

const fill = async (
  wrapper: Awaited<ReturnType<typeof mountOpen>>,
  schedule = 'off'
): Promise<void> => {
  await wrapper.get('[data-testid="input-new-task-name"]').setValue('Morning news')
  await wrapper.get('[data-testid="input-new-task-instruction"]').setValue('Summarise the news')
  await wrapper.get('[data-testid="select-new-task-schedule"]').setValue(schedule)
}

describe('NewTaskDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    createPrompt.mockResolvedValue({ id: 11 })
    createTask.mockResolvedValue(task)
    deletePrompt.mockResolvedValue(undefined)
    ensureTz.mockResolvedValue({ tz: 'Europe/Berlin' })
  })

  it('creates the instruction, then the task, and reports success', async () => {
    const wrapper = await mountOpen()
    await fill(wrapper)
    await wrapper.get('[data-testid="modal-new-task"]').trigger('submit')
    await flushPromises()

    const payload = createPrompt.mock.calls[0][0]
    expect(payload.topic).toMatch(/^task-morning-news-/)
    expect(payload.prompt).toBe('Summarise the news')
    expect(payload.selectionRules).toContain('Never select this topic')
    expect(createTask).toHaveBeenCalledWith(11, 'Morning news')
    expect(updateTask).not.toHaveBeenCalled()
    expect(wrapper.emitted('created')?.[0]).toEqual([task])
    expect(wrapper.emitted('close')).toBeTruthy()
    expect(success).toHaveBeenCalled()
  })

  it('saves a weekday schedule in the account time zone', async () => {
    updateTask.mockResolvedValue({ ...task, triggerType: 'schedule' })
    const wrapper = await mountOpen()
    await fill(wrapper, 'weekly')
    await wrapper.get('[data-testid="input-new-task-at"]').setValue('08:30')
    await wrapper.get('[data-testid="modal-new-task"]').trigger('submit')
    await flushPromises()

    expect(updateTask).toHaveBeenCalledWith(5, {
      triggerType: 'schedule',
      triggerConfig: { kind: 'weekly', at: '08:30', tz: 'Europe/Berlin', days: [1, 2, 3, 4, 5] },
      allowUnattended: true,
    })
  })

  it('says the schedule is missing when only the schedule failed', async () => {
    updateTask.mockRejectedValue(new Error('nope'))
    const wrapper = await mountOpen()
    await fill(wrapper, 'interval')
    await wrapper.get('[data-testid="modal-new-task"]').trigger('submit')
    await flushPromises()

    expect(wrapper.emitted('created')?.[0]).toEqual([task])
    expect(error).toHaveBeenCalledWith(expect.stringContaining('without a schedule'))
  })

  it('removes the orphan instruction when the task cannot be created', async () => {
    createTask.mockRejectedValue(new Error('boom'))
    const wrapper = await mountOpen()
    await fill(wrapper)
    await wrapper.get('[data-testid="modal-new-task"]').trigger('submit')
    await flushPromises()

    expect(deletePrompt).toHaveBeenCalledWith(11)
    expect(wrapper.emitted('created')).toBeUndefined()
    expect(error).toHaveBeenCalledWith(expect.stringContaining('Nothing was saved'))
  })
})
