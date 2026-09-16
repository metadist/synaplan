export interface PlatformDocRef {
  slug: string
  title: string
  url: string
}

const BOOK_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>'

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function isSafeDocUrl(url: string): boolean {
  if (url.startsWith('/') && !url.startsWith('//')) {
    return true
  }
  try {
    const parsed = new URL(url)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

export function parsePlatformDocs(value: unknown): PlatformDocRef[] | undefined {
  if (!Array.isArray(value)) {
    return undefined
  }

  const docs: PlatformDocRef[] = []
  for (const item of value) {
    if (!item || typeof item !== 'object') {
      continue
    }
    const rec = item as Record<string, unknown>
    const slug = typeof rec.slug === 'string' ? rec.slug.trim() : ''
    const title = typeof rec.title === 'string' ? rec.title.trim() : ''
    const url = typeof rec.url === 'string' ? rec.url.trim() : ''
    if (slug && title && url) {
      docs.push({ slug, title, url })
    }
  }

  return docs.length > 0 ? docs : undefined
}

/**
 * Render a `[Doc:slug]` token. Known slugs become a `.pill` link whose href
 * comes only from the provided docs list — never a constructed host.
 */
export function renderDocRefPill(
  slug: string,
  docs: PlatformDocRef[] | null | undefined,
  labels: { tooltip: string; ariaLabel: string }
): string {
  const doc = docs?.find((entry) => entry.slug === slug)
  if (!doc || !isSafeDocUrl(doc.url)) {
    return `[Doc:${slug}]`
  }

  const title = escapeHtml(doc.title)
  const href = escapeHtml(doc.url)
  const tooltip = escapeHtml(labels.tooltip)
  const ariaLabel = escapeHtml(labels.ariaLabel)
  const safeSlug = escapeHtml(slug)

  return `<a class="pill no-underline align-middle" href="${href}" target="_blank" rel="noopener noreferrer" title="${tooltip}" aria-label="${ariaLabel}" data-doc-slug="${safeSlug}">${BOOK_ICON}<span>${title}</span></a>`
}
