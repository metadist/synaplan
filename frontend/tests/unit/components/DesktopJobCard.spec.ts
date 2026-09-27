import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DesktopJobCard from '@/components/DesktopJobCard.vue'

const { getJob, cancelJob, confirm, success } = vi.hoisted(() => ({
  getJob: vi.fn(),
  cancelJob: vi.fn(),
  confirm: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/services/api/desktopApi', () => ({
  desktopApi: { getJob, cancelJob },
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error: vi.fn() }),
}))

const queuedJob = {
  id: 7,
  deviceId: 1,
  type: 'skill.run',
  skill: 'pptx',
  status: 'queued' as const,
  errorCode: null,
  result: null,
  chatId: 1,
  created: 1_756_500_000,
  updated: 1_756_500_000,
}

const mountCard = async () => {
  const wrapper = mount(DesktopJobCard, {
    props: { jobId: 7, deviceName: 'Studio Mac' },
  })
  await flushPromises()
  return wrapper
}

describe('DesktopJobCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getJob.mockResolvedValue({ ...queuedJob })
    confirm.mockResolvedValue(false)
  })

  it('hides the card without cancelling, and cancels only after confirm', async () => {
    const wrapper = await mountCard()
    expect(wrapper.get('[data-testid="btn-dismiss-job"]').attributes('aria-label')).toContain(
      'Hide this card'
    )
    expect(wrapper.get('[data-testid="btn-cancel-job"]').text()).toBe('Cancel task')
    expect(wrapper.text()).toContain('every 3 minutes')

    await wrapper.get('[data-testid="btn-dismiss-job"]').trigger('click')
    expect(wrapper.emitted('dismiss')).toHaveLength(1)
    expect(cancelJob).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="btn-cancel-job"]').trigger('click')
    await flushPromises()
    const message = confirm.mock.calls[0][0].message as string
    expect(message).toContain('Studio Mac')
    expect(message).toContain('pptx')
    expect(message).toContain('will not run')
    expect(cancelJob).not.toHaveBeenCalled()

    confirm.mockResolvedValue(true)
    cancelJob.mockResolvedValue({
      cancelled: true,
      job: { ...queuedJob, status: 'cancelled' },
    })
    await wrapper.get('[data-testid="btn-cancel-job"]').trigger('click')
    await flushPromises()
    expect(cancelJob).toHaveBeenCalledWith(7)
    expect(success).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="btn-cancel-job"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('This task was cancelled')
    expect(wrapper.text()).toContain('will not be saved here')
  })

  it('does not offer cancel once the task has finished', async () => {
    getJob.mockResolvedValue({ ...queuedJob, status: 'succeeded' })
    const wrapper = await mountCard()
    expect(wrapper.find('[data-testid="btn-cancel-job"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-dismiss-job"]').exists()).toBe(true)
  })
})
