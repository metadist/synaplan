/**
 * A full server answers a long route before PHP starts: 503 with a
 * `server_busy` body and `Retry-After` (AU3). That refusal is safe to retry —
 * the request never reached the application, so nothing was stored. Every
 * other response, including a 503 without the busy body, is returned as-is.
 */

export const BUSY_MAX_RETRIES = 3
export const BUSY_DEFAULT_RETRY_MS = 5000

/** The stream pool stayed full through every retry: the request was never taken. */
export class ServerBusyError extends Error {
  constructor() {
    super('server_busy')
    this.name = 'ServerBusyError'
  }
}

export function isBusyError(error: unknown): boolean {
  return error instanceof ServerBusyError
}

/** The busy bodies: chat names `server_busy`, the gateways name it in `code` or as `overloaded_error`. */
export function isServerBusyBody(bodyText: string): boolean {
  try {
    const body = JSON.parse(bodyText) as { error?: unknown }
    if (body.error === 'server_busy') return true
    if (typeof body.error === 'object' && body.error !== null) {
      const error = body.error as { code?: unknown; type?: unknown }
      return error.code === 'server_busy' || error.type === 'overloaded_error'
    }
  } catch {
    // Not JSON: not a busy refusal.
  }
  return false
}

/** `Retry-After` in seconds; the contract's 5 s when the header is absent or unusable. */
export function retryAfterMs(headers: Headers, fallbackMs: number = BUSY_DEFAULT_RETRY_MS): number {
  const raw = headers.get('Retry-After')
  const seconds = raw ? Number.parseInt(raw, 10) : Number.NaN
  return Number.isFinite(seconds) && seconds >= 0 ? seconds * 1000 : fallbackMs
}

export interface BusyRetryOptions {
  /** Called before each wait, with the retry number (1-based). */
  onBusy?: (retry: number) => void
  sleep?: (ms: number) => Promise<void>
  /** Extra delay in ms so parallel clients do not retry in lockstep. */
  jitterMs?: () => number
  maxRetries?: number
}

/** After the retries: is this refusal the busy one? (Reads the small error body.) */
export async function isBusyRefusal(response: Response): Promise<boolean> {
  if (response.status !== 503) return false
  const bodyText = await response.text().catch(() => '')
  return isServerBusyBody(bodyText)
}

/** POST JSON with busy retries; a final busy refusal throws {@link ServerBusyError}. */
export async function postJsonWithBusyRetry(
  url: string,
  headers: Record<string, string>,
  payload: Record<string, unknown>,
  credentials: RequestCredentials,
  onStatus?: (payload: { status: string }) => void
): Promise<Response> {
  const response = await fetchWithBusyRetry(
    () =>
      fetch(url, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
        credentials,
      }),
    { onBusy: () => onStatus?.({ status: 'busy' }) }
  )
  if (!response.ok && (await isBusyRefusal(response))) {
    throw new ServerBusyError()
  }
  return response
}

const defaultSleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Run `attempt` until it is admitted or the retries run out (3 by default).
 * A busy response is consumed through a clone; the returned response's body
 * is never read here, so a streamed answer still reaches the caller intact.
 */
export async function fetchWithBusyRetry(
  attempt: () => Promise<Response>,
  options: BusyRetryOptions = {}
): Promise<Response> {
  const maxRetries = options.maxRetries ?? BUSY_MAX_RETRIES
  const sleep = options.sleep ?? defaultSleep
  const jitter = options.jitterMs ?? (() => Math.random() * 1000)

  let retries = 0
  for (;;) {
    const response = await attempt()
    if (response.status !== 503 || retries >= maxRetries) return response
    const bodyText = await response
      .clone()
      .text()
      .catch(() => '')
    if (!isServerBusyBody(bodyText)) return response
    retries += 1
    options.onBusy?.(retries)
    await sleep(retryAfterMs(response.headers) + jitter())
  }
}
