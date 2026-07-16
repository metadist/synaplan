import type { z } from 'zod'
import {
  GetAdminModerationReportsResponseSchema,
  PostModerationReportCreateResponseSchema,
  PatchAdminModerationReportUpdateResponseSchema,
  PatchAdminModerationUserStatusResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

// Types are inferred from the generated Zod schemas (per AGENTS.md: never
// hand-write interfaces for API responses).
type ModerationReportsResponse = z.infer<typeof GetAdminModerationReportsResponseSchema>
export type ModerationReport = NonNullable<ModerationReportsResponse['reports']>[number]

/** Content-moderation enums, mirrored from the backend OpenAPI contract. */
export type ReportContentType = 'message' | 'file'
export type ReportReason =
  | 'spam'
  | 'harassment'
  | 'hate_speech'
  | 'violence'
  | 'sexual_content'
  | 'csae'
  | 'illegal'
  | 'other'
export type ReportStatus = 'open' | 'reviewed' | 'actioned' | 'dismissed'
export type AccountStatus = 'active' | 'suspended' | 'banned'

export interface SubmitReportRequest {
  contentType: ReportContentType
  contentId: number
  reason: ReportReason
  details?: string | null
}

/**
 * Report a piece of objectionable user-generated content (Apple Guideline 1.2).
 * Available to any authenticated user; the backend resolves the content owner
 * server-side so a client can't spoof who gets flagged.
 */
export async function submitContentReport(
  request: SubmitReportRequest
): Promise<z.infer<typeof PostModerationReportCreateResponseSchema>> {
  return httpClient('/api/v1/moderation/reports', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: PostModerationReportCreateResponseSchema,
  })
}

/** Operator-only moderation API (guarded by ROLE_ADMIN on the backend). */
export const adminModerationApi = {
  listReports: async (
    status: ReportStatus | '' = '',
    page = 1,
    perPage = 25
  ): Promise<ModerationReportsResponse> => {
    const params = new URLSearchParams({ page: page.toString(), perPage: perPage.toString() })
    if (status) {
      params.append('status', status)
    }
    return httpClient(`/api/v1/admin/moderation/reports?${params}`, {
      schema: GetAdminModerationReportsResponseSchema,
    })
  },

  updateReportStatus: async (
    id: number,
    status: ReportStatus
  ): Promise<z.infer<typeof PatchAdminModerationReportUpdateResponseSchema>> => {
    return httpClient(`/api/v1/admin/moderation/reports/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
      schema: PatchAdminModerationReportUpdateResponseSchema,
    })
  },

  updateUserStatus: async (
    userId: number,
    status: AccountStatus
  ): Promise<z.infer<typeof PatchAdminModerationUserStatusResponseSchema>> => {
    return httpClient(`/api/v1/admin/moderation/users/${userId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
      schema: PatchAdminModerationUserStatusResponseSchema,
    })
  },
}
