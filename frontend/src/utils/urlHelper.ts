/**
 * Normalize media URLs to absolute URLs
 * Converts relative paths to absolute URLs using API_BASE_URL
 */
export function normalizeMediaUrl(url: string | undefined | null): string {
  if (!url) return ''
  
  // Already absolute or data URL
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
    return url
  }
  
  // Get API base URL from environment
  // @ts-ignore - Vite env types
  const API_BASE_URL: string = import.meta.env?.VITE_API_BASE_URL || ''
  
  // Add leading slash if missing
  const normalizedPath = url.startsWith('/') ? url : `/${url}`
  
  return `${API_BASE_URL}${normalizedPath}`
}

