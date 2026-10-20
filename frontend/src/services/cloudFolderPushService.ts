import {
  ListFileCopiesResponseSchema,
  PostApiComputeWorkspacePushSendResponseSchema,
  PostApiFilesSendSendResponseSchema,
} from '@/generated/api-schemas'
import type { z } from 'zod'
import { ApiError, httpClient } from '@/services/api/httpClient'
import type { CloudFolderTargetItem } from '@/services/cloudFolderTargets'

export async function pushWorkspaceFile(path: string, connectionId: number) {
  return httpClient('/api/v1/compute/workspace/push', {
    method: 'POST',
    body: JSON.stringify({ path, connection_id: connectionId }),
    schema: PostApiComputeWorkspacePushSendResponseSchema,
  })
}

export async function pushGeneratedFile(fileId: number, connectionId: number) {
  return httpClient(`/api/v1/files/${fileId}/send`, {
    method: 'POST',
    body: JSON.stringify({ destination: 'webdav', connection_id: connectionId }),
    schema: PostApiFilesSendSendResponseSchema,
  })
}

export async function pushPlatformFile(fileId: number, identityId: number) {
  return httpClient(`/api/v1/files/${fileId}/send`, {
    method: 'POST',
    body: JSON.stringify({ destination: 'platform', identity_id: identityId }),
    schema: PostApiFilesSendSendResponseSchema,
  })
}

export type FileCopyRow = NonNullable<
  z.infer<typeof ListFileCopiesResponseSchema>['copies']
>[number]

export async function listFileCopies(fileIds: number[]) {
  if (fileIds.length === 0) return []
  const data = await httpClient(`/api/v1/files/copies?ids=${fileIds.join(',')}`, {
    method: 'GET',
    schema: ListFileCopiesResponseSchema,
  })
  return data.copies ?? []
}

function asContext(details: Record<string, unknown> | undefined): Record<string, string> {
  const raw = details?.context
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return {}
  }
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(raw)) {
    if (typeof value === 'string') {
      out[key] = value
    }
  }
  return out
}

export function pushFailureCopy(
  err: unknown,
  t: (key: string, values?: Record<string, unknown>) => string,
  te: (key: string) => boolean
): string {
  if (err instanceof ApiError) {
    const code = err.code ?? 'unreachable'
    const key = `files.push.failure.${code}`
    if (te(key)) {
      const ctx = asContext(err.details)
      return t(key, {
        connection: ctx.connection || t('files.push.action'),
        target: ctx.target || '',
        newName: ctx.newName || '',
      })
    }
  }
  return t('files.push.failed')
}

export function pushSuccessFolder(
  reference: string | null | undefined,
  target: CloudFolderTargetItem,
  fileName: string
): string {
  if (typeof reference === 'string' && reference.trim() !== '') {
    return reference
  }
  return `${target.folder}/${fileName}`
}
