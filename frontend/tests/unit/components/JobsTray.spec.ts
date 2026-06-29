import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import JobsTray from '@/components/jobs/JobsTray.vue'
import { useMediaJobsStore } from '@/stores/mediaJobs'

describe('JobsTray', () => {
  let pinia: Pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
  })

  it('shows the empty state when there are no active jobs', () => {
    const w = mount(JobsTray, { props: { open: true }, global: { plugins: [pinia] } })
    expect(w.find('[data-testid="jobs-tray-empty"]').exists()).toBe(true)
    expect(w.findAll('[data-testid="job-row"]')).toHaveLength(0)
  })

  it('renders one row per active job', () => {
    const store = useMediaJobsStore()
    store.activeJobs.push({
      jobId: 'j1',
      type: 'video',
      state: 'running',
      chatId: 7,
      prompt: 'a cat',
    })
    store.activeJobs.push({
      jobId: 'j2',
      type: 'image',
      state: 'running',
      chatId: 8,
      prompt: 'a logo',
    })

    const w = mount(JobsTray, { props: { open: true }, global: { plugins: [pinia] } })

    expect(w.find('[data-testid="jobs-tray-empty"]').exists()).toBe(false)
    expect(w.findAll('[data-testid="job-row"]')).toHaveLength(2)
  })

  it('does not render the panel when closed', () => {
    useMediaJobsStore().activeJobs.push({ jobId: 'j1', type: 'video', state: 'running', chatId: 7 })
    const w = mount(JobsTray, { props: { open: false }, global: { plugins: [pinia] } })
    expect(w.find('[data-testid="jobs-tray"]').exists()).toBe(false)
  })

  it('emits close from the close button', async () => {
    const w = mount(JobsTray, { props: { open: true }, global: { plugins: [pinia] } })
    await w.find('[data-testid="jobs-tray-close"]').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })
})
