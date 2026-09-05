import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiSavedTasksListResponseSchema,
  GetApiSavedTasksRunsResponseSchema,
  PatchApiSavedTasksUpdateResponseSchema,
  PostApiSavedTasksCopyResponseSchema,
  PostApiSavedTasksCreateResponseSchema,
  PostApiSavedTasksResumeResponseSchema,
  PostApiSavedTasksRunResponseSchema,
} from '@/generated/api-schemas'

type RawTask = NonNullable<z.infer<typeof GetApiSavedTasksListResponseSchema>['tasks']>[number]
type RawRun = NonNullable<z.infer<typeof GetApiSavedTasksRunsResponseSchema>['runs']>[number]

/**
 * Language-neutral summary contract (see backend SavedTaskSummary): `key`
 * selects the sentence template, `params` holds CODES (when/reads/saves) plus
 * raw interpolation values (at/tz/minutes). The card translates every part —
 * the backend never sends prose, so the sentence is single-language.
 */
export interface SavedTaskSummary {
  key: string
  params: Record<string, string>
}

export interface SavedTask {
  id: number
  promptId: number
  name: string
  enabled: boolean
  triggerType: string
  triggerConfig: Record<string, unknown> | null
  graph: Record<string, unknown> | null
  allowUnattended: boolean
  chatId: number | null
  nextRunAt: string | null
  lastRunAt: string | null
  consecutiveFailures: number
  autoPaused: boolean
  summary: SavedTaskSummary
  /** First ~60 characters of the underlying instruction ("what runs"). */
  instructionPreview: string | null
}

export interface SavedTaskRun {
  id: number
  status: string
  trigger: string
  messageId: number | null
  planSnapshot: { cards?: unknown[] } | null
  error: string | null
  started: string | null
  finished: string | null
  created: number
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null
}

function asTask(raw: RawTask | undefined): SavedTask {
  if (!raw || raw.id == null || raw.promptId == null || !raw.name) {
    throw new Error('Malformed Saved Task response')
  }
  const params = asRecord(raw.summary?.params) ?? {}
  return {
    id: raw.id,
    promptId: raw.promptId,
    name: raw.name,
    enabled: raw.enabled === true,
    triggerType: raw.triggerType ?? 'manual',
    triggerConfig: asRecord(raw.triggerConfig),
    graph: asRecord(raw.graph),
    allowUnattended: raw.allowUnattended === true,
    chatId: raw.chatId ?? null,
    nextRunAt: raw.nextRunAt ?? null,
    lastRunAt: raw.lastRunAt ?? null,
    consecutiveFailures: raw.consecutiveFailures ?? 0,
    autoPaused: raw.autoPaused === true,
    summary: {
      key: raw.summary?.key ?? 'config.savedTasks.summary.simple',
      params: Object.fromEntries(
        Object.entries(params).map(([key, value]) => [key, String(value ?? '')])
      ),
    },
    instructionPreview: raw.instructionPreview ?? null,
  }
}

function asRun(raw: RawRun | undefined): SavedTaskRun {
  if (!raw || raw.id == null) {
    throw new Error('Malformed Saved Task run response')
  }
  const snapshot = asRecord(raw.planSnapshot)
  return {
    id: raw.id,
    status: raw.status ?? 'failed',
    trigger: raw.trigger ?? 'manual',
    messageId: raw.messageId ?? null,
    planSnapshot: snapshot,
    error: raw.error ?? null,
    started: raw.started ?? null,
    finished: raw.finished ?? null,
    created: raw.created ?? 0,
  }
}

export const savedTasksApi = {
  async list(): Promise<SavedTask[]> {
    const data = await httpClient('/api/v1/saved-tasks', {
      schema: GetApiSavedTasksListResponseSchema,
    })
    return (data.tasks ?? []).map((task) => asTask(task))
  },

  async create(promptId: number, name: string): Promise<SavedTask> {
    const data = await httpClient('/api/v1/saved-tasks', {
      method: 'POST',
      body: JSON.stringify({ promptId, name }),
      schema: PostApiSavedTasksCreateResponseSchema,
    })
    return asTask(data.task)
  },

  async update(id: number, patch: Record<string, unknown>): Promise<SavedTask> {
    const data = await httpClient(`/api/v1/saved-tasks/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(patch),
      schema: PatchApiSavedTasksUpdateResponseSchema,
    })
    return asTask(data.task)
  },

  /**
   * Runs the task now. `message` is an optional EXTRA instruction — when
   * omitted, the backend runs the task's stored instruction.
   */
  async run(id: number, message = ''): Promise<{ task: SavedTask; run: SavedTaskRun }> {
    const data = await httpClient(`/api/v1/saved-tasks/${id}/run`, {
      method: 'POST',
      body: JSON.stringify(message ? { message } : {}),
      schema: PostApiSavedTasksRunResponseSchema,
    })
    return { task: asTask(data.task), run: asRun(data.run) }
  },

  async runs(id: number): Promise<{ runs: SavedTaskRun[]; retention: string }> {
    const data = await httpClient(`/api/v1/saved-tasks/${id}/runs`, {
      schema: GetApiSavedTasksRunsResponseSchema,
    })
    return {
      runs: (data.runs ?? []).map((run) => asRun(run)),
      retention: data.retention ?? '',
    }
  },

  async copy(id: number): Promise<SavedTask> {
    const data = await httpClient(`/api/v1/saved-tasks/${id}/copy`, {
      method: 'POST',
      schema: PostApiSavedTasksCopyResponseSchema,
    })
    return asTask(data.task)
  },

  async resume(id: number): Promise<SavedTask> {
    const data = await httpClient(`/api/v1/saved-tasks/${id}/resume`, {
      method: 'POST',
      schema: PostApiSavedTasksResumeResponseSchema,
    })
    return asTask(data.task)
  },
}
