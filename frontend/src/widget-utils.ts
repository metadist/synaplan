/**
 * Widget utility functions
 */

/**
 * Detects the API URL from the current script location
 * Handles both entry point (foo.com/widget.js) and chunk (foo.com/chunks/widget-hash.js)
 */
export function detectApiUrl(): string {
  const url = new URL(import.meta.url)
  let pathname = url.pathname

  // If we're in a chunks/ directory, go up one level
  if (pathname.includes('/chunks/')) {
    pathname = pathname.substring(0, pathname.indexOf('/chunks/'))
  } else {
    // Otherwise just remove the filename
    pathname = pathname.replace(/\/[^\/]+$/, '')
  }

  return `${url.origin}${pathname}`
}
