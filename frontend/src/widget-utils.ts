/**
 * Shared utilities for Synaplan Widget scripts
 */

/**
 * Detects the API base URL from the script's import.meta.url
 *
 * Example:
 *   https://app.synaplan.com/widget.js -> https://app.synaplan.com
 *   https://example.com/synaplan/widget-loader.js -> https://example.com/synaplan
 *
 * @returns The detected API base URL (origin + path without filename)
 */
export function detectApiUrl(): string {
  const url = new URL(import.meta.url)
  const basePath = url.pathname.replace(/\/[^\/]+$/, '') // Remove filename
  return `${url.origin}${basePath}`
}
