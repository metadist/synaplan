import { api } from './apiService'
import { httpClient, getApiBaseUrl, refreshAccessToken } from './api/httpClient'
import { saveOrDownloadBlob } from './api/nativeDownload'
import { isNativeApp } from './api/nativeRuntime'
import { getNativeAccessToken } from './api/nativeAuth'

export type UploadCheckReason =
  | 'rate_limit_exceeded'
  | 'file_too_large'
  | 'file_empty'
  | 'extension_not_allowed'
  | 'storage_exceeded'

export interface UploadCheckResponse {
  allowed: boolean
  reason?: UploadCheckReason
  message?: string
  max_file_size: number
  allowed_extensions: string[]
  remaining: number
  used?: number
  limit?: number
  // Server-published PHP `max_file_uploads` ceiling for a single multipart
  // request. The UI MUST batch larger selections into chunks of this size or
  // PHP will silently truncate $_FILES and only the first N uploads survive.
  // Optional for backwards compat with older servers — frontend falls back
  // to a conservative 20 in that case.
  max_files_per_request?: number
}

/**
 * UploadBlockedError — thrown when the server's pre-flight check rejects an upload.
 * Catch this in the UI to show a clear, i18n-friendly message instead of waiting
 * for the slow upload to time out.
 */
export class UploadBlockedError extends Error {
  public readonly reason: UploadCheckReason
  public readonly check: UploadCheckResponse
  public readonly filename: string

  constructor(filename: string, check: UploadCheckResponse) {
    super(check.message ?? 'Upload not allowed')
    this.name = 'UploadBlockedError'
    this.filename = filename
    this.reason = (check.reason ?? 'storage_exceeded') as UploadCheckReason
    this.check = check
  }
}

/**
 * Pre-flight check before uploading a file body. Sends only metadata so the
 * server can reject quota/size/extension/rate-limit violations in milliseconds.
 */
export async function checkUpload(
  filename: string,
  size: number,
  mime?: string
): Promise<UploadCheckResponse> {
  return httpClient<UploadCheckResponse>('/api/v1/files/check-upload', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ filename, size, mime }),
  })
}

export interface UploadFileOptions {
  files: File[]
  groupKey?: string
  processLevel?: 'store' | 'extract' | 'vectorize' | 'full'
  onProgress?: (progress: UploadProgress) => void
  /**
   * Phase transitions during a single batch transfer. Lets the UI show
   * actionable hints ("taking longer than usual…", "saving on server…")
   * instead of a frozen percentage. Optional — callers that don't care can
   * omit it.
   */
  onPhase?: (phase: UploadPhase) => void
  signal?: AbortSignal
  /**
   * Abort the transfer if NO upload-progress event fires for this many ms
   * while the request body is still being sent. This is the guard against
   * the "stuck at 89% forever" failure where a proxy / body-size / timeout
   * limit silently stops draining the body (the request never reaches PHP,
   * so no error ever comes back). A live connection always emits progress,
   * so a genuinely slow-but-working upload is never cut off. Default 45s.
   */
  stallTimeoutMs?: number
  /**
   * Emit a 'slow' phase (soft, non-fatal warning) if no upload progress
   * fires for this many ms — earlier than {@link stallTimeoutMs} — so the
   * UI can reassure the user before we give up. Default 10s.
   */
  slowWarningMs?: number
  /**
   * Abort if the server sends no response within this many ms AFTER the
   * body is fully uploaded. Defaults to disabled (undefined) because
   * synchronous `vectorize`/`full` uploads can legitimately take minutes.
   * Callers that only `store` (fast) should pass a tight value so a hung
   * backend surfaces an error instead of spinning forever.
   */
  serverTimeoutMs?: number
}

export type UploadPhase = 'uploading' | 'slow' | 'finishing'

export interface UploadProgress {
  loaded: number
  total: number
  percentage: number
}

/**
 * Why an upload failed, in machine-readable form, so the UI can show a
 * specific, localized, secret-free message + a remediation hint instead of
 * a raw status code or — worse — nothing at all.
 */
export type UploadFailureCode =
  | 'too_large' // 413 — file/body exceeds a server or proxy limit
  | 'forbidden' // 403 — blocked by an edge layer (WAF / firewall / proxy)
  | 'gateway' // 502 / 503 / 504 — LB or backend node unavailable
  | 'server_error' // other 5xx
  | 'network' // connection dropped / DNS / TLS (xhr error, status 0)
  | 'transfer_stalled' // body stopped draining mid-flight (the 89% case)
  | 'server_timeout' // body sent, backend never answered
  | 'invalid_response' // 2xx but body wasn't valid JSON
  | 'http_error' // any other non-2xx with a parseable message

/**
 * UploadFailedError — a transfer-level failure with a classified reason.
 * Carries the HTTP status (when there was one) and the percentage reached
 * so the UI can tell the user *where* it broke without leaking any payload.
 */
export class UploadFailedError extends Error {
  constructor(
    public readonly code: UploadFailureCode,
    message: string,
    public readonly status?: number,
    public readonly percentReached?: number
  ) {
    super(message)
    this.name = 'UploadFailedError'
  }
}

export interface UploadedFile {
  id: number
  filename: string
  size: number
  mime: string
  path: string
  group_key: string
  extracted_text_length?: number
  extraction_strategy?: string
  chunks_created?: number
  vectorized?: boolean
  processing_time_ms: number
}

export interface UploadResponse {
  success: boolean
  files: UploadedFile[]
  errors: Array<{
    filename: string
    error: string
  }>
  total_time_ms: number
  process_level: string
}

export interface FileItem {
  id: number
  filename: string
  path: string
  file_type: string
  file_size: number
  mime: string
  status: string
  text_preview: string
  uploaded_at: number
  uploaded_date: string
  message_id: number | null
  is_attached: boolean
  group_key?: string
}

export interface FileListResponse {
  success: boolean
  files: FileItem[]
  pagination: {
    page: number
    limit: number
    total: number
    pages: number
  }
}

// Fallback when the server pre-flight response doesn't include
// `max_files_per_request` (older deployments) — match PHP's historical
// default to stay on the safe side.
const DEFAULT_MAX_FILES_PER_REQUEST = 20

/**
 * Upload files with processing and progress tracking.
 *
 * Performs a metadata-only pre-flight check first (filename + size) so that
 * uploads which would be rejected for quota, size, extension, or rate-limit
 * reasons fail FAST and deterministically — preventing client/proxy timeouts
 * when streaming a large body that is going to be rejected anyway.
 *
 * Throws {@link UploadBlockedError} when the pre-flight rejects a file. The UI
 * should catch this and surface the localized message to the user.
 *
 * Splits the file list into batches sized by the server's published
 * `max_files_per_request` (PHP `max_file_uploads`) so the user can drop 50+
 * files into the RAG manager without PHP silently truncating $_FILES at
 * the first 20. Batches run sequentially so per-file errors stay attributable
 * and the visible progress bar advances monotonically.
 *
 * Uses XMLHttpRequest for the actual transfer to support upload progress.
 *
 * @param options Upload options with files, processing level, and optional progress callback
 * @returns Aggregated upload response across all batches
 */
export const uploadFiles = async (options: UploadFileOptions): Promise<UploadResponse> => {
  // Pre-flight all files concurrently — N small HEAD-style metadata
  // requests in parallel beat N sequential round-trips when the user
  // drops a folder of files. We throw on the FIRST rejection we
  // encounter (in original file-order) so the user sees the same error
  // regardless of network jitter.
  const checks = await Promise.all(
    options.files.map((file) => checkUpload(file.name, file.size, file.type))
  )
  for (let i = 0; i < options.files.length; i++) {
    if (!checks[i].allowed) {
      throw new UploadBlockedError(options.files[i].name, checks[i])
    }
  }

  // Use the cap reported by the pre-flight (same value from every file —
  // all checks hit the same backend). Falls back to 20 if absent.
  const batchSize = Math.max(
    1,
    Number(checks[0]?.max_files_per_request) || DEFAULT_MAX_FILES_PER_REQUEST
  )

  // Total upload size in bytes — kept in real units across batches so the
  // UI's `formatFileSize(loaded)` / `formatFileSize(total)` render truthful
  // values. The first iteration used a synthetic `totalFiles * 1000` scale
  // which made the Files-page progress label read as e.g. "50 KB of 50 KB"
  // while uploading 50 files (flagged in PR #1013 review).
  const totalBytes = options.files.reduce((sum, file) => sum + file.size, 0)

  // Single-batch fast path: no chunking overhead when the selection already
  // fits in one request (covers the vast majority of widget uploads).
  if (options.files.length <= batchSize) {
    return uploadFilesBatch(options, options.files, 0, totalBytes)
  }

  const aggregated: UploadResponse = {
    success: true,
    files: [],
    errors: [],
    total_time_ms: 0,
    process_level: options.processLevel ?? 'vectorize',
  }

  let bytesUploaded = 0
  for (let offset = 0; offset < options.files.length; offset += batchSize) {
    const batch = options.files.slice(offset, offset + batchSize)

    const batchResult = await uploadFilesBatch(options, batch, bytesUploaded, totalBytes)

    bytesUploaded += batch.reduce((sum, file) => sum + file.size, 0)

    aggregated.files.push(...batchResult.files)
    aggregated.errors.push(...batchResult.errors)
    aggregated.total_time_ms += batchResult.total_time_ms ?? 0
    aggregated.process_level = batchResult.process_level ?? aggregated.process_level
    if (!batchResult.success) {
      aggregated.success = false
    }
  }

  return aggregated
}

/**
 * Classify a non-2xx XHR response into an {@link UploadFailedError} with a
 * machine-readable code, preferring the backend's JSON `error` message when
 * present (e.g. the max_file_uploads 413) and falling back to a clear,
 * status-derived message when a proxy returns non-JSON (e.g. an HTML 413/502).
 */
const buildHttpError = (xhr: XMLHttpRequest): UploadFailedError => {
  let serverMessage = ''
  try {
    const parsed = JSON.parse(xhr.responseText)
    if (parsed && typeof parsed.error === 'string') serverMessage = parsed.error
  } catch {
    // Non-JSON body (proxy/HTML error page) — fall back to status mapping.
  }

  const status = xhr.status
  if (status === 413) {
    return new UploadFailedError(
      'too_large',
      serverMessage || 'The server or a proxy rejected this upload as too large',
      status
    )
  }
  if (status === 403) {
    // The upload endpoint itself never returns 403 (it answers 401/400/413),
    // so a 403 here is an edge layer — WAF, firewall, or reverse proxy —
    // blocking the request body before it reaches the backend.
    return new UploadFailedError(
      'forbidden',
      serverMessage || 'Upload blocked by a security layer before reaching the server',
      status
    )
  }
  if (status === 502 || status === 503 || status === 504) {
    return new UploadFailedError(
      'gateway',
      serverMessage || `Server temporarily unavailable (${status})`,
      status
    )
  }
  if (status >= 500) {
    return new UploadFailedError(
      'server_error',
      serverMessage || `Server error (${status})`,
      status
    )
  }
  return new UploadFailedError(
    'http_error',
    serverMessage || `Upload failed: ${status} ${xhr.statusText}`,
    status
  )
}

/**
 * Upload a single batch of files (≤ server max_file_uploads).
 *
 * Reports progress in bytes within the parent call's `totalBytes` so the
 * caller sees a single, monotonically-increasing progress bar across all
 * batches AND can render `loaded`/`total` directly as file sizes.
 */
const uploadFilesBatch = async (
  options: UploadFileOptions,
  batch: File[],
  bytesAlreadyUploaded: number,
  totalBytes: number
): Promise<UploadResponse> => {
  const buildFormData = (): FormData => {
    const fd = new FormData()
    batch.forEach((file) => fd.append('files[]', file))
    if (options.groupKey) fd.append('group_key', options.groupKey)
    if (options.processLevel) fd.append('process_level', options.processLevel)
    // Explicit declared count lets the backend detect PHP's silent
    // `max_file_uploads` truncation and respond 413 instead of swallowing
    // the missing files.
    fd.append('file_count', String(batch.length))
    return fd
  }

  // No progress callback → simple fetch path (no chunking visualisation needed).
  if (!options.onProgress) {
    return httpClient<UploadResponse>('/api/v1/files/upload', {
      method: 'POST',
      body: buildFormData(),
      signal: options.signal,
    })
  }

  // Translate per-batch xhr progress into a cumulative byte counter that
  // spans the entire user-visible upload, so the caller's progress bar
  // moves monotonically (0 → 100%) instead of jumping back to 0% on every
  // batch boundary. `loaded`/`total` are real bytes — `FilesView.vue`
  // formats them with `formatFileSize()` for the status text.
  const reportProgress = (batchLoaded: number) => {
    if (!options.onProgress) return
    const safeTotal = totalBytes > 0 ? totalBytes : 1
    const loaded = Math.min(safeTotal, bytesAlreadyUploaded + batchLoaded)
    options.onProgress({
      loaded,
      total: safeTotal,
      percentage: Math.min(100, Math.round((loaded / safeTotal) * 100)),
    })
  }

  const STALL_MS = options.stallTimeoutMs ?? 45_000
  const SLOW_MS = options.slowWarningMs ?? 10_000
  const SERVER_MS = options.serverTimeoutMs // undefined => no server-response deadline

  const totalForLog = totalBytes > 0 ? totalBytes : 1
  const startedAt = Date.now()
  // Diagnostics are intentionally payload-free: only filename, size, phase
  // and timing — never headers, body, or response text. Safe to leave on in
  // production so support can reconstruct a stuck upload from the console.
  const diag = (event: string, extra?: Record<string, unknown>) => {
    const names = batch.map((f) => f.name).join(', ')
    console.info(
      `[upload] ${event} — files="${names}" bytes=${totalForLog} +${Date.now() - startedAt}ms`,
      extra ?? ''
    )
  }

  const sendXhr = (isRetry = false): Promise<UploadResponse> =>
    new Promise<UploadResponse>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      const baseUrl = getApiBaseUrl()

      // Distinguish a watchdog-triggered abort from a user-triggered one so
      // the UI can tell "you cancelled" apart from "the connection died".
      let watchdogFailure: UploadFailedError | null = null
      let lastPercent = 0
      let stallTimer: ReturnType<typeof setTimeout> | null = null
      let slowTimer: ReturnType<typeof setTimeout> | null = null
      let serverTimer: ReturnType<typeof setTimeout> | null = null

      const clearTimers = () => {
        if (stallTimer) clearTimeout(stallTimer)
        if (slowTimer) clearTimeout(slowTimer)
        if (serverTimer) clearTimeout(serverTimer)
        stallTimer = slowTimer = serverTimer = null
      }

      const failViaWatchdog = (err: UploadFailedError) => {
        watchdogFailure = err
        diag('watchdog-abort', { code: err.code, percent: lastPercent })
        xhr.abort()
      }

      // (Re)arm the transfer-stall guard: fires only if NO progress arrives
      // for STALL_MS while the body is still being sent.
      const armTransferWatchdogs = () => {
        if (stallTimer) clearTimeout(stallTimer)
        if (slowTimer) clearTimeout(slowTimer)
        slowTimer = setTimeout(() => {
          options.onPhase?.('slow')
          diag('slow', { percent: lastPercent })
        }, SLOW_MS)
        stallTimer = setTimeout(() => {
          failViaWatchdog(
            new UploadFailedError(
              'transfer_stalled',
              `Upload stalled at ${lastPercent}% — the connection stopped sending data`,
              undefined,
              lastPercent
            )
          )
        }, STALL_MS)
      }

      const onAbort = () => xhr.abort()
      const cleanup = () => {
        clearTimers()
        if (options.signal) {
          options.signal.removeEventListener('abort', onAbort)
        }
      }

      if (options.signal) {
        if (options.signal.aborted) {
          reject(new DOMException('Upload cancelled', 'AbortError'))
          return
        }
        options.signal.addEventListener('abort', onAbort)
      }

      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable) {
          lastPercent = Math.min(100, Math.round((event.loaded / (event.total || 1)) * 100))
          reportProgress(event.loaded)
          armTransferWatchdogs()
        }
      })

      // Body fully flushed to the OS socket. `load` fires only on a successful
      // upload (unlike `loadend`, which also fires on abort/error), so the
      // "finishing" phase and the server-response deadline are armed only when
      // the transfer actually completed. Abort/error are handled by their own
      // listeners below, which run cleanup().
      xhr.upload.addEventListener('load', () => {
        if (stallTimer) clearTimeout(stallTimer)
        if (slowTimer) clearTimeout(slowTimer)
        stallTimer = slowTimer = null
        options.onPhase?.('finishing')
        diag('body-sent')
        if (SERVER_MS !== undefined) {
          serverTimer = setTimeout(() => {
            failViaWatchdog(
              new UploadFailedError(
                'server_timeout',
                'File uploaded but the server did not respond in time',
                undefined,
                100
              )
            )
          }, SERVER_MS)
        }
      })

      xhr.addEventListener('load', async () => {
        cleanup()
        if (xhr.status >= 200 && xhr.status < 300) {
          diag('done', { status: xhr.status })
          try {
            const response = JSON.parse(xhr.responseText)
            resolve(response)
          } catch {
            reject(
              new UploadFailedError('invalid_response', 'Invalid response from server', xhr.status)
            )
          }
        } else if (xhr.status === 401 && !isRetry) {
          try {
            const refreshResult = await refreshAccessToken()
            if (refreshResult.success) {
              resolve(await sendXhr(true))
            } else {
              window.location.href = '/login?reason=session_expired'
              reject(new Error('Session expired'))
            }
          } catch {
            window.location.href = '/login?reason=session_expired'
            reject(new Error('Session expired'))
          }
        } else if (xhr.status === 401) {
          window.location.href = '/login?reason=session_expired'
          reject(new Error('Session expired'))
        } else {
          diag('http-error', { status: xhr.status })
          reject(buildHttpError(xhr))
        }
      })

      xhr.addEventListener('error', () => {
        cleanup()
        diag('network-error', { percent: lastPercent })
        reject(
          new UploadFailedError(
            'network',
            'Network error during upload — the connection was lost',
            undefined,
            lastPercent
          )
        )
      })

      xhr.addEventListener('abort', () => {
        cleanup()
        // A watchdog abort carries its own classified error; otherwise this
        // was the user (or unmount) cancelling, which stays an AbortError.
        if (watchdogFailure) {
          reject(watchdogFailure)
        } else {
          reject(new DOMException('Upload cancelled', 'AbortError'))
        }
      })

      xhr.open('POST', `${baseUrl}/api/v1/files/upload`)

      // Web authenticates via HttpOnly cookies (credentialed CORS). The native
      // shell is cross-origin against `Access-Control-Allow-Origin: *`, where
      // the browser REJECTS credentialed requests outright (the upload would
      // fail with a bare network error). So on native we omit credentials and
      // replay the Bearer access token instead — mirroring httpClient (Epic 3,
      // required by 7.1's cross-origin upload).
      if (isNativeApp()) {
        xhr.withCredentials = false
        const accessToken = getNativeAccessToken()
        if (accessToken) {
          xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`)
        }
      } else {
        xhr.withCredentials = true
      }

      const csrfToken = sessionStorage.getItem('csrf_token')
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken)
      }

      options.onPhase?.('uploading')
      diag('start')
      armTransferWatchdogs()
      xhr.send(buildFormData())
    })

  return sendXhr()
}

export interface FileListOptions {
  groupKey?: string
  search?: string
  fileType?: string
  dateFrom?: number
  dateTo?: number
  page?: number
  limit?: number
}

/**
 * List user's files with optional filtering and search
 */
export const listFiles = async (options: FileListOptions = {}): Promise<FileListResponse> => {
  const params: Record<string, string | number> = {
    page: options.page ?? 1,
    limit: options.limit ?? 50,
  }

  if (options.groupKey) params.group_key = options.groupKey
  if (options.search) params.search = options.search
  if (options.fileType) params.file_type = options.fileType
  if (options.dateFrom) params.date_from = options.dateFrom
  if (options.dateTo) params.date_to = options.dateTo

  const response = await api.get<FileListResponse>('/api/v1/files', { params })
  return response.data
}

/**
 * Delete a file
 *
 * @param fileId File ID to delete
 * @returns Success response
 */
export const deleteFile = async (
  fileId: number
): Promise<{ success: boolean; message: string }> => {
  const response = await api.delete<{ success: boolean; message: string }>(
    `/api/v1/files/${fileId}`
  )
  return response.data
}

/**
 * Delete multiple files
 *
 * @param fileIds Array of file IDs to delete
 * @returns Array of results
 */
export const deleteMultipleFiles = async (
  fileIds: number[]
): Promise<Array<{ fileId: number; success: boolean; error?: string }>> => {
  const results = await Promise.allSettled(fileIds.map((id) => deleteFile(id)))

  return results.map((result, index) => ({
    fileId: fileIds[index],
    success: result.status === 'fulfilled',
    error: result.status === 'rejected' ? String(result.reason) : undefined,
  }))
}

/**
 * Get file groups (unique group keys with file counts from RAG documents)
 *
 * @returns Array of group names with file counts
 */
export const getFileGroups = async (): Promise<Array<{ name: string; count: number }>> => {
  const response = await api.get<{
    success: boolean
    groups: Array<{ name: string; count: number }>
  }>('/api/v1/files/groups')
  return response.data.groups
}

/**
 * Get file content/text
 *
 * @param fileId File ID
 * @returns File content details
 */
export const getFileContent = async (
  fileId: number
): Promise<{
  id: number
  filename: string
  file_path: string
  file_type: string
  file_size?: number
  mime?: string
  extracted_text: string
  status: string
  uploaded_at: number
  uploaded_date: string
}> => {
  const response = await api.get<{
    id: number
    filename: string
    file_path: string
    file_type: string
    file_size?: number
    mime?: string
    extracted_text: string
    status: string
    uploaded_at: number
    uploaded_date: string
  }>(`/api/v1/files/${fileId}/content`)
  return response.data
}

/**
 * Download a file
 *
 * @param fileId File ID
 * @param filename Original filename for download
 */
export const downloadFile = async (fileId: number, filename: string): Promise<void> => {
  const blob = await httpClient<Blob>(`/api/v1/files/${fileId}/download`, {
    responseType: 'blob',
  })

  // Web → anchor download; native → Filesystem + share sheet (Epic 7.1).
  await saveOrDownloadBlob(blob, filename)
}

/**
 * Make file public and generate share link
 *
 * @param fileId File ID
 * @param expiryDays Days until expiry (default: 7, 0 = never)
 * @returns Share info
 */
export const shareFile = async (
  fileId: number,
  expiryDays: number = 7
): Promise<{
  success: boolean
  share_url: string
  share_token: string
  expires_at: number | null
  is_public: boolean
}> => {
  const response = await api.post<{
    success: boolean
    share_url: string
    share_token: string
    expires_at: number | null
    is_public: boolean
  }>(`/api/v1/files/${fileId}/share`, { expiry_days: expiryDays })
  return response.data
}

/**
 * Revoke public access to file
 *
 * @param fileId File ID
 */
export const unshareFile = async (
  fileId: number
): Promise<{ success: boolean; message: string }> => {
  const response = await api.delete<{ success: boolean; message: string }>(
    `/api/v1/files/${fileId}/share`
  )
  return response.data
}

/**
 * Get share info for file
 *
 * @param fileId File ID
 */
export const getShareInfo = async (
  fileId: number
): Promise<{
  is_public: boolean
  share_url: string | null
  share_token: string | null
  expires_at: number | null
  is_expired: boolean
}> => {
  const response = await api.get<{
    is_public: boolean
    share_url: string | null
    share_token: string | null
    expires_at: number | null
    is_expired: boolean
  }>(`/api/v1/files/${fileId}/share`)
  return response.data
}

export interface StorageStats {
  limit: number
  usage: number
  remaining: number
  percentage: number
  limit_formatted: string
  usage_formatted: string
  remaining_formatted: string
  max_file_size: number
  max_file_size_formatted: string
}

export interface StorageStatsResponse {
  success: boolean
  user_level: string
  storage: StorageStats
}

/**
 * Get storage quota statistics
 */
export async function getStorageStats(): Promise<StorageStatsResponse> {
  return httpClient<StorageStatsResponse>('/api/v1/files/storage-stats')
}

/**
 * Get groupKey for a file
 */
export async function getFileGroupKey(fileId: number): Promise<{
  success: boolean
  groupKey: string | null
  isVectorized: boolean
  chunks: number
  status: string
  needsMigration: boolean
  mariadbChunks: number
  qdrantChunks: number
}> {
  const response = await httpClient<{
    success: boolean
    groupKey: string | null
    isVectorized: boolean
    chunks: number
    status: string
    needsMigration: boolean
    mariadbChunks: number
    qdrantChunks: number
  }>(`/api/v1/files/${fileId}/group-key`)
  return response
}

/**
 * Migrate file vectors from MariaDB to Qdrant
 */
export async function migrateFileToQdrant(fileId: number): Promise<{
  success: boolean
  migrated: number
  errors: number
}> {
  const response = await httpClient<{
    success: boolean
    migrated: number
    errors: number
  }>(`/api/v1/files/${fileId}/migrate`, {
    method: 'POST',
  })
  return response
}

/**
 * Update groupKey for a file
 */
export async function updateFileGroupKey(
  fileId: number,
  groupKey: string
): Promise<{
  success: boolean
  chunksUpdated: number
  message: string
}> {
  const response = await httpClient<{
    success: boolean
    chunksUpdated: number
    message: string
  }>(`/api/v1/files/${fileId}/group-key`, {
    method: 'PUT',
    body: JSON.stringify({ groupKey }),
  })
  return response
}

/**
 * Re-vectorize a file
 */
export async function reVectorizeFile(
  fileId: number,
  groupKey?: string
): Promise<{
  success: boolean
  chunksCreated: number
  extractedTextLength: number
  groupKey: string
  message: string
}> {
  const response = await httpClient<{
    success: boolean
    chunksCreated: number
    extractedTextLength: number
    groupKey: string
    message: string
  }>(`/api/v1/files/${fileId}/re-vectorize`, {
    method: 'POST',
    body: JSON.stringify({ groupKey: groupKey || '' }),
  })
  return response
}

/**
 * Trigger extraction + vectorization for a stored file (fire-and-forget from UI)
 */
export async function processFile(
  fileId: number
): Promise<{ success: boolean; status: string; error?: string }> {
  const response = await httpClient<{ success: boolean; status: string; error?: string }>(
    `/api/v1/files/${fileId}/process`,
    { method: 'POST' }
  )
  return response
}

export default {
  uploadFiles,
  checkUpload,
  listFiles,
  deleteFile,
  deleteMultipleFiles,
  getFileGroups,
  getFileContent,
  downloadFile,
  shareFile,
  unshareFile,
  getShareInfo,
  getStorageStats,
  getFileGroupKey,
  updateFileGroupKey,
  reVectorizeFile,
  migrateFileToQdrant,
  processFile,
}
