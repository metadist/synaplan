import { z } from 'zod'
import { httpClient } from './httpClient'

export const MarketingNewsItemSchema = z.object({
  title: z.string(),
  url: z.string(),
  excerpt: z.string(),
  imageUrl: z.string().nullable().optional(),
  publishedAt: z.string().nullable().optional(),
  tags: z.array(z.string()).default([]),
})

export const MarketingNewsResponseSchema = z.object({
  items: z.array(MarketingNewsItemSchema).default([]),
})

export type MarketingNewsItem = z.infer<typeof MarketingNewsItemSchema>

/**
 * Fetch marketing news for the anonymous guest landing.
 *
 * Public endpoint; returns an empty list when the admin master switch is off.
 */
export const getLandingNews = async (lang: string): Promise<MarketingNewsItem[]> => {
  const data = await httpClient<unknown>(`/api/v1/news/landing?lang=${encodeURIComponent(lang)}`, {
    skipAuth: true,
  })
  const parsed = MarketingNewsResponseSchema.parse(data)
  return parsed.items
}
