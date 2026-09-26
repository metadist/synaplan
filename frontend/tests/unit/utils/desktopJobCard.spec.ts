import { describe, expect, it } from 'vitest'

import {
  deviceResultText,
  jobCardView,
  readDismissedJobIds,
  rememberDismissedJobId,
  RESTORE_WINDOW_MS,
  shouldRestoreDesktopJob,
} from '@/utils/desktopJobCard'

describe('jobCardView', () => {
  it('shows the computer’s own reason instead of blaming a turned-off skill', () => {
    const view = jobCardView(
      'failed',
      'skill_disabled',
      { message: "The skill 'hello-files' is not allowed to run when nobody is at the keyboard." },
      false
    )

    expect(view.phase).toBe('failed')
    expect(view.detail).toEqual({
      type: 'device',
      text: "The skill 'hello-files' is not allowed to run when nobody is at the keyboard.",
    })
  })

  it('shows a local failure’s result text instead of a generic skill-failed line', () => {
    const view = jobCardView(
      'failed',
      'local_error',
      { message: 'Choose a model to chat with.' },
      false
    )

    expect(view.detail).toEqual({ type: 'device', text: 'Choose a model to chat with.' })
  })

  it('maps a failure without a result to a specific reason', () => {
    expect(jobCardView('failed', 'timeout', null, true).detail).toEqual({
      type: 'key',
      key: 'timedOut',
    })
    expect(jobCardView('failed', 'unknown_skill', {}, false).detail).toEqual({
      type: 'key',
      key: 'unknownSkill',
    })
    expect(jobCardView('failed', 'skill_disabled', null, false).detail).toEqual({
      type: 'key',
      key: 'skillDisabled',
    })
    expect(jobCardView('failed', 'local_error', { message: '   ' }, false).detail).toEqual({
      type: 'key',
      key: 'localError',
    })
  })

  it('keeps a leased job as running after the wait, and a queued job as still waiting', () => {
    expect(jobCardView('leased', null, null, true)).toEqual({
      phase: 'running',
      detail: { type: 'key', key: 'stillRunning' },
    })
    expect(jobCardView('queued', null, null, true)).toEqual({
      phase: 'waiting',
      detail: { type: 'key', key: 'queuedTooLong' },
    })
    expect(jobCardView('leased', null, null, false).phase).toBe('running')
  })

  it('does not describe a cancelled task as a missing answer', () => {
    expect(jobCardView('cancelled', 'timeout', null, true)).toEqual({
      phase: 'cancelled',
      detail: { type: 'key', key: 'cancelledDetail' },
    })
  })
})

describe('deviceResultText', () => {
  it('collapses whitespace and caps a long reason', () => {
    const text = deviceResultText({ message: `  line\n${'x'.repeat(400)}` })
    expect(text?.startsWith('line x')).toBe(true)
    expect(text?.endsWith('…')).toBe(true)
    expect(text?.length).toBe(280)
  })
})

describe('shouldRestoreDesktopJob', () => {
  const now = 1_800_000_000_000

  it('restores a waiting job for this chat and skips a dismissed one', () => {
    const job = { id: 4, chatId: 9, status: 'leased', created: 1, updated: 1 }
    expect(shouldRestoreDesktopJob(job, 9, new Set(), now)).toBe(true)
    expect(shouldRestoreDesktopJob(job, 9, new Set([4]), now)).toBe(false)
    expect(shouldRestoreDesktopJob(job, 3, new Set(), now)).toBe(false)
  })

  it('restores a recent failure and drops one older than a day', () => {
    const recent = {
      id: 1,
      chatId: 9,
      status: 'failed',
      created: Math.floor((now - 60_000) / 1000),
    }
    const old = {
      id: 2,
      chatId: 9,
      status: 'failed',
      created: Math.floor((now - RESTORE_WINDOW_MS - 1000) / 1000),
    }
    expect(shouldRestoreDesktopJob(recent, 9, new Set(), now)).toBe(true)
    expect(shouldRestoreDesktopJob(old, 9, new Set(), now)).toBe(false)
  })
})

describe('dismissed job ids', () => {
  it('round-trips through storage', () => {
    const bag = new Map<string, string>()
    const storage = {
      getItem: (key: string) => bag.get(key) ?? null,
      setItem: (key: string, value: string) => {
        bag.set(key, value)
      },
    }
    rememberDismissedJobId(storage, 12)
    expect(readDismissedJobIds(storage).has(12)).toBe(true)
  })
})
