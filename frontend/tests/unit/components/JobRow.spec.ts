import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import JobRow from '@/components/jobs/JobRow.vue'
import type { TrayJob } from '@/stores/mediaJobs'

const baseJob: TrayJob = {
  jobId: 'j1',
  type: 'video',
  state: 'running',
  percent: 42,
  chatId: 7,
  prompt: 'a cat surfing',
}

describe('JobRow', () => {
  it('renders the prompt, chat title and progress', () => {
    const w = mount(JobRow, { props: { job: baseJob, chatTitle: 'Marketing ideas' } })
    expect(w.text()).toContain('a cat surfing')
    expect(w.text()).toContain('Marketing ideas')
    expect(w.text()).toContain('42')
  })

  it('emits open with the chat id and cancel with the job id', async () => {
    const w = mount(JobRow, { props: { job: baseJob } })
    await w.find('[data-testid="job-row-open"]').trigger('click')
    await w.find('[data-testid="job-row-stop"]').trigger('click')

    expect(w.emitted('open')?.[0]).toEqual([7])
    expect(w.emitted('cancel')?.[0]).toEqual(['j1'])
  })

  it('hides the Open action when the job has no chat', () => {
    const w = mount(JobRow, { props: { job: { ...baseJob, chatId: null } } })
    expect(w.find('[data-testid="job-row-open"]').exists()).toBe(false)
    // Stop is always available.
    expect(w.find('[data-testid="job-row-stop"]').exists()).toBe(true)
  })

  it('falls back to the kind label when there is no prompt', () => {
    const w = mount(JobRow, {
      props: { job: { ...baseJob, prompt: undefined, percent: undefined } },
    })
    expect(w.text().toLowerCase()).toContain('video')
  })
})
