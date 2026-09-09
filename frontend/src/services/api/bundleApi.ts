import type { z } from 'zod'
import {
  BundleDocument as BundleDocumentSchema,
  GetApiBundleSectionsResponseSchema,
  PostApiBundleExportResponseSchema,
  PostApiBundleImportResponseSchema,
  PostApiBundlePreviewResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type BundleScope = z.infer<typeof BundleDocumentSchema>['scope']
export type BundleConflict = 'skip' | 'overwrite'
export type BundleDocument = z.infer<typeof BundleDocumentSchema>
export type BundleSection = z.infer<typeof GetApiBundleSectionsResponseSchema>['sections'][number]
export type BundlePreview = z.infer<typeof PostApiBundlePreviewResponseSchema>
export type BundleChecklistItem = BundlePreview['sections'][number]['items'][number]
export type BundleImportResult = z.infer<typeof PostApiBundleImportResponseSchema>

const BUNDLE_BASE = '/api/v1/bundle'

export const bundleApi = {
  async sections(scope: BundleScope = 'user'): Promise<BundleSection[]> {
    const data = await httpClient(`${BUNDLE_BASE}/sections?scope=${encodeURIComponent(scope)}`, {
      schema: GetApiBundleSectionsResponseSchema,
    })
    return data.sections
  },

  async export(kinds: string[], scope: BundleScope = 'user'): Promise<BundleDocument> {
    return httpClient(`${BUNDLE_BASE}/export`, {
      method: 'POST',
      body: JSON.stringify({ kinds, scope }),
      schema: PostApiBundleExportResponseSchema,
    })
  },

  async preview(bundle: unknown): Promise<BundlePreview> {
    return httpClient(`${BUNDLE_BASE}/preview`, {
      method: 'POST',
      body: JSON.stringify({ bundle }),
      schema: PostApiBundlePreviewResponseSchema,
    })
  },

  async import(bundle: unknown, conflict: BundleConflict = 'skip'): Promise<BundleImportResult> {
    return httpClient(`${BUNDLE_BASE}/import`, {
      method: 'POST',
      body: JSON.stringify({ bundle, options: { conflict } }),
      schema: PostApiBundleImportResponseSchema,
    })
  },
}
