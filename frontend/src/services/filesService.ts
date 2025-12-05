import { api } from './apiService'
import { httpClient } from './api/httpClient'

export interface UploadFileOptions {
  files: File[]
  groupKey?: string
  processLevel?: 'extract' | 'vectorize' | 'full'
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

/**
 * Upload files with processing
 * 
 * @param options Upload options with files and processing level
 * @returns Upload response with file details
 */
export const uploadFiles = async (options: UploadFileOptions): Promise<UploadResponse> => {
  const formData = new FormData()
  
  // Add files
  options.files.forEach(file => {
    formData.append('files[]', file)
  })
  
  // Add optional parameters
  if (options.groupKey) {
    formData.append('group_key', options.groupKey)
  }
  
  if (options.processLevel) {
    formData.append('process_level', options.processLevel)
  }
  
  const response = await api.post<UploadResponse>('/api/v1/files/upload', formData)
  
  return response.data
}

/**
 * List user's files with optional filtering
 * 
 * @param groupKey Optional filter by group
 * @param page Page number (default: 1)
 * @param limit Items per page (default: 50)
 * @returns List of files with pagination
 */
export const listFiles = async (
  groupKey?: string,
  page: number = 1,
  limit: number = 50
): Promise<FileListResponse> => {
  const params: Record<string, string | number> = {
    page,
    limit
  }
  
  if (groupKey) {
    params.group_key = groupKey
  }
  
  const response = await api.get<FileListResponse>('/api/v1/files', { params })
  return response.data
}

/**
 * Delete a file
 * 
 * @param fileId File ID to delete
 * @returns Success response
 */
export const deleteFile = async (fileId: number): Promise<{ success: boolean; message: string }> => {
  const response = await api.delete<{ success: boolean; message: string }>(`/api/v1/files/${fileId}`)
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
  const results = await Promise.allSettled(
    fileIds.map(id => deleteFile(id))
  )
  
  return results.map((result, index) => ({
    fileId: fileIds[index],
    success: result.status === 'fulfilled',
    error: result.status === 'rejected' ? String(result.reason) : undefined
  }))
}

/**
 * Get file groups (aggregated from existing files)
 * 
 * This extracts unique group_key values from the user's files
 * 
 * @returns Array of group names with file counts
 */
export const getFileGroups = async (): Promise<Array<{ name: string; count: number }>> => {
  // Get all files (first page with high limit to get all groups)
  const response = await listFiles(undefined, 1, 1000)
  
  // Aggregate by group_key
  const groupMap = new Map<string, number>()
  
  response.files.forEach(file => {
    if (file.group_key) {
      groupMap.set(file.group_key, (groupMap.get(file.group_key) || 0) + 1)
    }
  })
  
  return Array.from(groupMap.entries())
    .map(([name, count]) => ({ name, count }))
    .sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * Get file content/text
 * 
 * @param fileId File ID
 * @returns File content details
 */
export const getFileContent = async (fileId: number): Promise<{
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
    responseType: 'blob'
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
export const unshareFile = async (fileId: number): Promise<{ success: boolean; message: string }> => {
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

/**
 * Get storage quota statistics
 */
export async function getStorageStats(): Promise<{
  success: boolean
  user_level: string
  storage: {
    limit: number
    usage: number
    remaining: number
    percentage: number
    limit_formatted: string
    usage_formatted: string
    remaining_formatted: string
  }
}> {
  const response = await httpClient<{
    success: boolean
    user_level: string
    storage: {
      limit: number
      usage: number
      remaining: number
      percentage: number
      limit_formatted: string
      usage_formatted: string
      remaining_formatted: string
    }
  }>('/api/v1/files/storage-stats')
  return response
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
}> {
  const response = await httpClient<{
    success: boolean
    groupKey: string | null
    isVectorized: boolean
    chunks: number
    status: string
  }>(`/api/v1/files/${fileId}/group-key`)
  return response
}

/**
 * Update groupKey for a file
 */
export async function updateFileGroupKey(fileId: number, groupKey: string): Promise<{
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
    body: JSON.stringify({ groupKey })
  })
  return response
}

/**
 * Re-vectorize a file
 */
export async function reVectorizeFile(fileId: number, groupKey?: string): Promise<{
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
    body: JSON.stringify({ groupKey: groupKey || 'DEFAULT' })
  })
  return response
}

export default {
  uploadFiles,
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
  reVectorizeFile
}

