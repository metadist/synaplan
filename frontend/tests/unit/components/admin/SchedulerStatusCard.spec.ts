import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/i18n'
import type { SchedulerStatus } from '@/services/api/scheduler'
import SchedulerStatusCard from '@/components/admin/SchedulerStatusCard.vue'

const storeState = vi.hoisted(() => ({
  status: null as SchedulerStatus | null,
  loading: false,
  loadFailed: false,
  canRead: true,
  load: vi.fn(),
}))

vi.mock('@/stores/scheduler', () => ({
  useSchedulerStore: () => storeState,
}))

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({
    formatRelativeTime: () => '5 minutes ago',
  }),
}))

const DOCS_URL =
  'https://github.com/metadist/synaplan/blob/main/docs/ADMIN.md#background-jobs-scheduler'

type LaneId = SchedulerStatus['lanes'][number]['lane']

function lane(
  id: LaneId,
  overrides: Partial<SchedulerStatus['lanes'][number]> = {}
): SchedulerStatus['lanes'][number] {
  return {
    lane: id,
    lastStartedAt: 1_700_000_000,
    lastFinishedAt: 1_700_000_100,
    failedJobs: [],
    unfinishedJobs: [],
    ...overrides,
  }
}

function payload(overrides: Partial<SchedulerStatus> = {}): SchedulerStatus {
  return {
    state: 'running',
    maxAgeSeconds: 180,
    checkedAt: 1_700_000_200,
    lastRunAt: 1_700_000_100,
    lanes: (['tick', 'tasks', 'hourly', 'daily', 'health'] as const).map((id) => lane(id)),
    ...overrides,
  }
}

function mountCard() {
  return mount(SchedulerStatusCard)
}

describe('SchedulerStatusCard', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en'
    storeState.status = null
    storeState.loading = false
    storeState.loadFailed = false
    storeState.canRead = true
    storeState.load.mockReset()
  })

  it('says it is checking while the status is still loading', async () => {
    storeState.loading = true
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.get('[data-testid="scheduler-state-line"]').text()).toBe(
      'Checking background jobs…'
    )
    expect(wrapper.find('[data-testid="item-scheduler-lane"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="btn-scheduler-refresh"]').attributes('disabled')).toBe('')
  })

  it('explains a failed read and keeps refresh available', async () => {
    storeState.loadFailed = true
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.get('[data-testid="scheduler-state-line"]').text()).toBe(
      'We could not load the background job status. Reload the page.'
    )
    expect(wrapper.find('[data-testid="item-scheduler-lane"]').exists()).toBe(false)
    const refresh = wrapper.get('[data-testid="btn-scheduler-refresh"]')
    expect(refresh.attributes('disabled')).toBeUndefined()
    await refresh.trigger('click')
    expect(storeState.load).toHaveBeenCalled()
  })

  it('says when jobs last ran', async () => {
    storeState.status = payload()
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.get('[data-testid="scheduler-state-line"]').text()).toBe(
      'Background jobs are running. Last run: 5 minutes ago.'
    )
    expect(wrapper.find('[data-testid="link-scheduler-docs"]').exists()).toBe(false)
    const lanes = wrapper.findAll('[data-testid="item-scheduler-lane"]')
    expect(lanes.map((row) => row.attributes('data-lane'))).toEqual([
      'tick',
      'tasks',
      'hourly',
      'daily',
      'health',
    ])
    expect(lanes[1]?.text()).toContain('Saved Tasks')
    expect(lanes[0]?.text()).toContain('5 minutes ago')
    expect(lanes[0]?.get('[data-testid="scheduler-lane-time"]').classes()).toContain(
      'text-[var(--status-success-text)]'
    )
  })

  it('names failed jobs and points at the scheduler log', async () => {
    storeState.status = payload({
      lanes: [
        lane('tick', { failedJobs: ['app:updates:check'] }),
        lane('tasks', { failedJobs: ['app:digest:run', 'app:not-a-real-command'] }),
        lane('hourly'),
        lane('daily'),
        lane('health'),
      ],
    })
    const wrapper = mountCard()
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('1 job failed: update check. The scheduler log has the details.')
    expect(text).toContain(
      '2 jobs failed: message digest and app:not-a-real-command. The scheduler log has the details.'
    )
  })

  it('names jobs that started and have not finished', async () => {
    storeState.status = payload({
      lanes: [
        lane('tick', {
          lastFinishedAt: null,
          unfinishedJobs: ['app:digest:run'],
        }),
        lane('tasks', { lastFinishedAt: null }),
        lane('hourly'),
        lane('daily'),
        lane('health'),
      ],
    })
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.text()).toContain(
      'Started but not finished yet: message digest (still running, or stopped by its time limit).'
    )
    expect(wrapper.get('[data-lane="tasks"]').text()).toContain('Not run yet')
  })

  it('says jobs stopped, what is not happening, and how to recover', async () => {
    storeState.status = payload({ state: 'stale' })
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.get('[data-testid="scheduler-state-line"]').text()).toBe(
      'Background jobs have stopped. Last run: 5 minutes ago. Scheduled tasks, reminders and clean-ups are not running. Restart the scheduler service.'
    )
    const docs = wrapper.get('[data-testid="link-scheduler-docs"]')
    expect(docs.attributes('href')).toBe(DOCS_URL)
    expect(docs.attributes('target')).toBe('_blank')
    expect(docs.attributes('rel')).toContain('noopener')
    expect(docs.classes()).toContain('btn-secondary')
    expect(wrapper.get('code').text()).toBe('docker compose restart scheduler')
    expect(
      wrapper.get('[data-lane="tick"] [data-testid="scheduler-lane-time"]').classes()
    ).toContain('text-[var(--status-neutral-text)]')
  })

  it('offers the scheduler guide when jobs have never run', async () => {
    storeState.status = payload({
      state: 'never',
      lastRunAt: null,
      lanes: (['tick', 'tasks', 'hourly', 'daily', 'health'] as const).map((id) =>
        lane(id, { lastStartedAt: null, lastFinishedAt: null })
      ),
    })
    const wrapper = mountCard()
    await flushPromises()

    expect(wrapper.get('[data-testid="scheduler-state-line"]').text()).toBe(
      'Background jobs have never run on this server.'
    )
    const docs = wrapper.get('[data-testid="link-scheduler-docs"]')
    expect(docs.text()).toContain('How to start the scheduler')
    expect(docs.attributes('href')).toBe(DOCS_URL)
    expect(docs.attributes('target')).toBe('_blank')
    expect(docs.attributes('rel')).toContain('noopener')
    expect(docs.classes()).toContain('btn-primary')
    expect(wrapper.get('[data-lane="tick"]').text()).toContain('Not run yet')
  })

  it('stays hidden for someone who cannot read scheduler status', () => {
    storeState.canRead = false
    const wrapper = mountCard()
    expect(wrapper.find('[data-testid="section-scheduler-status"]').exists()).toBe(false)
  })
})
