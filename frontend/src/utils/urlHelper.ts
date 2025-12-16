import { useConfigStore } from '@/stores/config'

/**
 * Normalize media URLs to absolute URLs
 * Converts relative paths to absolute URLs using appBaseUrl
 */
export function normalizeMediaUrl(url: string | undefined | null): string {
  if (!url) return ''

  // Already absolute or data URL
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
    return url
  }

  const config = useConfigStore()

  // Add leading slash if missing
  const normalizedPath = url.startsWith('/') ? url : `/${url}`

  return `${config.appBaseUrl}${normalizedPath}`
}
