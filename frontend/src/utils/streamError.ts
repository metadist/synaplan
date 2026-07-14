/**
 * Issue #1265: distinguish a transport-level SSE drop from a real backend error.
 *
 * When the SSE connection drops mid-turn (`Connection interrupted` / `Failed to
 * connect`), the backend keeps the turn alive after the client disconnect
 * (#1230) and persists the assistant answer as usual. Showing a permanent
 * "Connection interrupted" error bubble is therefore wrong: it contradicts what
 * a page refresh shows (the persisted answer or, for a still-running turn, the
 * in-progress task cards). The chat view treats a recoverable drop by
 * reconciling with the server instead of rendering a phantom error.
 *
 * A REAL backend error (model-not-configured, rate limit, cost budget, chat not
 * found, provider failure, …) always carries a structured signal — a machine
 * code, install/model hints, rate-limit fields, or a specific message — and must
 * keep surfacing to the user.
 */

/** The transport-drop messages emitted by the SSE client (chatApi.ts). */
const TRANSPORT_DROP_MESSAGES = new Set(['Connection interrupted', 'Failed to connect'])

/** Minimal shape of the `error` status payload the stream delivers. */
export interface StreamErrorData {
  status?: string
  error?: unknown
  message?: unknown
  code?: unknown
  install_command?: unknown
  limit_type?: unknown
  topup_available?: unknown
}

/**
 * True when the error is purely a dropped/failed SSE connection with no backend
 * error signal — i.e. safe to recover by reconciling with the persisted state
 * rather than showing an error bubble.
 */
export function isRecoverableStreamError(data: StreamErrorData): boolean {
  if (data.status !== 'error') return false

  // Any structured backend error signal means this is a genuine failure.
  if (data.code || data.install_command || data.limit_type || data.topup_available) {
    return false
  }

  const text = typeof data.error === 'string' ? data.error : ''
  return TRANSPORT_DROP_MESSAGES.has(text)
}
