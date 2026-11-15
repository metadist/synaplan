import type { StreamUpdatePayload } from '@/types/chatStream'
import { reconnectDelayMs, resumeFrom } from '@/services/chat/stream/reconnect'
import { hasSessionHint } from '@/services/sessionHint'
import { fetchWithBusyRetry, isBusyRefusal } from './busyRetry'
import { getApiBaseUrl, readSseBody, refreshAccessToken, sseTokenFetchInit } from './chatApi'

export interface StreamPostRun {
  onUpdate: (data: StreamUpdatePayload) => void
  doFetch: () => Promise<Response>
  signal: AbortSignal
  isStopped: () => boolean
}

function dropped(runId: string, onUpdate: (data: StreamUpdatePayload) => void): void {
  onUpdate(
    runId
      ? { status: 'error', code: 'reconnect_exhausted', error: 'Connection interrupted' }
      : { status: 'error', error: 'Connection interrupted' }
  )
}

export async function runStreamPost(run: StreamPostRun): Promise<void> {
  let runId = ''
  let lastSeq = 0
  let sawId = false
  const observe = (data: StreamUpdatePayload) => {
    if (data.status === 'run_started' && typeof data.runId === 'string' && data.runId !== '') {
      runId = data.runId
    }
    run.onUpdate(data)
  }
  const noteId = (seq: number) => {
    lastSeq = seq
    sawId = true
  }
  const onBusy = () => {
    if (!run.isStopped()) run.onUpdate({ status: 'busy' })
  }
  try {
    let response = await fetchWithBusyRetry(run.doFetch, { onBusy })
    if (response.status === 401) {
      const refreshed = await refreshAccessToken()
      if (refreshed && !run.isStopped())
        response = await fetchWithBusyRetry(run.doFetch, { onBusy })
    }
    if (run.isStopped()) return
    if (!response.ok) {
      await reportHttpError(response, run.onUpdate)
      return
    }
    if (!response.body) {
      run.onUpdate({ status: 'error', error: 'Streaming not supported by this browser' })
      return
    }
    const terminal = await readSseBody(response.body, observe, run.isStopped, noteId)
    if (!terminal && !run.isStopped()) {
      const resumed = await resumeAttachedStream(
        runId,
        () => sawId,
        () => lastSeq,
        observe,
        noteId,
        run.isStopped,
        run.signal
      )
      if (!resumed && !run.isStopped()) {
        console.error('❌ Stream ended without completion event')
        dropped(runId, run.onUpdate)
      }
    }
  } catch (error) {
    if (run.isStopped() || (error instanceof DOMException && error.name === 'AbortError')) return
    if (
      runId &&
      (await resumeAttachedStream(
        runId,
        () => sawId,
        () => lastSeq,
        observe,
        noteId,
        run.isStopped,
        run.signal
      ))
    ) {
      return
    }
    console.error('🚫 Stream setup error:', error)
    if (!runId) {
      run.onUpdate({ status: 'error', error: 'Failed to connect' })
      return
    }
    dropped(runId, run.onUpdate)
  }
}

async function resumeAttachedStream(
  runId: string,
  sawId: () => boolean,
  lastSeq: () => number,
  onUpdate: (data: StreamUpdatePayload) => void,
  noteId: (seq: number) => void,
  isStopped: () => boolean,
  signal: AbortSignal
): Promise<boolean> {
  for (let attempt = 0; attempt < 3; attempt++) {
    if (signal.aborted || isStopped()) return false
    onUpdate({ status: 'reconnecting' })
    await waitForReconnect(reconnectDelayMs(attempt), signal)
    if (signal.aborted || isStopped()) return false
    const plan = resumeFrom(sawId(), lastSeq())
    if (plan.rebuild) onUpdate({ status: 'stream_rebuild' })
    try {
      const resumed = await readAttach(runId, plan.from, signal, isStopped, onUpdate, noteId)
      if (resumed) return true
    } catch (error) {
      if (
        signal.aborted ||
        isStopped() ||
        (error instanceof DOMException && error.name === 'AbortError')
      ) {
        return false
      }
    }
  }
  return false
}

async function readAttach(
  runId: string,
  from: number,
  signal: AbortSignal,
  isStopped: () => boolean,
  onUpdate: (data: StreamUpdatePayload) => void,
  noteId: (seq: number) => void
): Promise<boolean> {
  const authInit = sseTokenFetchInit()
  const params = new URLSearchParams({ runId, from: String(from) })
  const load = () =>
    fetch(`${getApiBaseUrl()}/api/v1/messages/stream/attach?${params}`, {
      method: 'GET',
      credentials: authInit.credentials,
      headers: {
        ...((authInit.headers as Record<string, string> | undefined) ?? {}),
        Accept: 'text/event-stream',
      },
      signal,
    })
  let response = await load()
  if (response.status === 401) {
    const refreshed = await refreshAccessToken()
    if (refreshed && !signal.aborted && !isStopped()) response = await load()
  }
  if (signal.aborted || isStopped() || !response.ok || !response.body) return false
  return readSseBody(response.body, onUpdate, isStopped, noteId)
}

function waitForReconnect(delayMs: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, delayMs)
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer)
        resolve()
      },
      { once: true }
    )
  })
}

async function reportHttpError(
  response: Response,
  onUpdate: (data: StreamUpdatePayload) => void
): Promise<void> {
  console.error(`🚫 Stream connection failed (HTTP ${response.status})`)
  if (await isBusyRefusal(response)) {
    onUpdate({ status: 'error', code: 'server_busy' })
    return
  }
  onUpdate(
    response.status === 401 && !hasSessionHint()
      ? {
          status: 'error',
          code: 'auth_required',
          error: 'Authentication required. Please log in again to continue.',
          message: 'Your session has expired. Please refresh the page and log in again.',
        }
      : { status: 'error', code: response.status === 429 ? 'rate_limited' : 'server_error' }
  )
}
