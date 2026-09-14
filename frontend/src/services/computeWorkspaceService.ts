import { z } from 'zod'
import {
  GetApiComputeWorkspaceFilesResponseSchema,
  GetApiComputeWorkspaceShowResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from '@/services/api/httpClient'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'

export type ComputeWorkspaceInfo = z.infer<typeof GetApiComputeWorkspaceShowResponseSchema>
export type ComputeWorkspaceFile = z.infer<
  typeof GetApiComputeWorkspaceFilesResponseSchema
>['files'][number]

export async function getWorkspace(): Promise<ComputeWorkspaceInfo> {
  return httpClient('/api/v1/compute/workspace', {
    schema: GetApiComputeWorkspaceShowResponseSchema,
  })
}

export async function listWorkspaceFiles(path = ''): Promise<ComputeWorkspaceFile[]> {
  const query = path === '' ? '' : `?path=${encodeURIComponent(path)}`
  const body = await httpClient(`/api/v1/compute/workspace/files${query}`, {
    schema: GetApiComputeWorkspaceFilesResponseSchema,
  })
  return body.files
}

export function workspaceFileUrl(path: string): string {
  return `/api/v1/compute/workspace/files/${path
    .split('/')
    .filter(Boolean)
    .map(encodeURIComponent)
    .join('/')}`
}

export function workspaceFileName(path: string): string {
  const parts = path.split('/').filter(Boolean)
  return parts[parts.length - 1] ?? 'file'
}

export async function downloadWorkspaceFile(path: string): Promise<void> {
  const blob = await httpClient<Blob>(workspaceFileUrl(path), { responseType: 'blob' })
  await saveOrDownloadBlob(blob, workspaceFileName(path))
}

export async function loadWorkspaceFileBlob(path: string): Promise<Blob> {
  return httpClient<Blob>(workspaceFileUrl(path), { responseType: 'blob' })
}

export async function deleteWorkspace(): Promise<void> {
  await httpClient('/api/v1/compute/workspace', { method: 'DELETE', responseType: 'text' })
}
