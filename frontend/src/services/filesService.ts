import { api } from './apiService'
import { httpClient, getApiBaseUrl, refreshAccessToken } from './api/httpClient'

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
  signal?: AbortSignal
}

export interface UploadProgress {
  loaded: number
  total: number
  percentage: number
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

  const sendXhr = (isRetry = false): Promise<UploadResponse> =>
    new Promise<UploadResponse>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      const baseUrl = getApiBaseUrl()

      const onAbort = () => xhr.abort()
      const cleanup = () => {
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
          reportProgress(event.loaded)
        }
      })

      xhr.addEventListener('load', async () => {
        cleanup()
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const response = JSON.parse(xhr.responseText)
            resolve(response)
          } catch {
            reject(new Error('Invalid response from server'))
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
          try {
            const errorResponse = JSON.parse(xhr.responseText)
            reject(new Error(errorResponse.error || `Upload failed: ${xhr.status}`))
          } catch {
            reject(new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`))
          }
        }
      })

      xhr.addEventListener('error', () => {
        cleanup()
        reject(new Error('Network error during upload'))
      })

      xhr.addEventListener('abort', () => {
        cleanup()
        reject(new DOMException('Upload cancelled', 'AbortError'))
      })

      xhr.open('POST', `${baseUrl}/api/v1/files/upload`)
      xhr.withCredentials = true

      const csrfToken = sessionStorage.getItem('csrf_token')
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken)
      }

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

  // Create blob and trigger download
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  window.URL.revokeObjectURL(url)
  document.body.removeChild(a)
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
