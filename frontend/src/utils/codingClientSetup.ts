/**
 * Base URL copied into Claude Code setup commands.
 *
 * The web app often talks to `/api` on the page origin. `/v1/messages` is
 * served by the API origin. An empty runtime API base means "same origin for
 * `/api`", which is not that gateway, so the server hint (APP_URL) wins.
 */
export function codingClientBaseUrl(
  apiBaseUrl: string,
  serverHint: string,
  windowOrigin: string
): string {
  const fromApi = trimOrigin(apiBaseUrl)
  if (fromApi !== '') {
    return fromApi
  }
  const hint = trimOrigin(serverHint)
  if (/^https?:\/\//i.test(hint)) {
    return hint
  }
  return trimOrigin(windowOrigin)
}

export function codingClientSetupSnippet(baseUrl: string): string {
  return [
    `export ANTHROPIC_BASE_URL="${baseUrl}"`,
    'export ANTHROPIC_API_KEY="sk_your_synaplan_api_key"',
    '# or: export ANTHROPIC_AUTH_TOKEN="sk_your_synaplan_api_key"',
    '# Set exactly one credential variable.',
    'claude',
  ].join('\n')
}

/** A coding client can send only when a user key or the operator key will pay. */
export function providerKeyReady(effectiveSource: string | undefined): boolean {
  return effectiveSource === 'user' || effectiveSource === 'operator'
}

function trimOrigin(value: string): string {
  return value.trim().replace(/\/+$/, '')
}
