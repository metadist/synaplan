const BOOLEAN_SETTINGS = new Set([
  'ENABLED',
  'ALLOW_OPERATOR_KEY',
  'MCP_TOOLS_ENABLED',
  'MCP_TOOLS_WITH_CLIENT_TOOLS',
  'CONTEXT_INJECTION_ENABLED',
  'BUDGET_NOTICE_ENABLED',
  'SESSION_SUMMARY_ENABLED',
])

type Translate = (key: string, values?: Record<string, string>) => string

/**
 * Readable old → new lines for a messages-gateway audit subject.
 * Empty when the row is not a gateway change.
 */
export function gatewayAuditDetail(action: string, subject: unknown, translate: Translate): string {
  if (!action.startsWith('messages_gateway.')) return ''
  if (!subject || typeof subject !== 'object' || !('changes' in subject)) return ''
  const changes = (subject as { changes?: unknown }).changes
  if (!changes || typeof changes !== 'object') return ''

  const lines: string[] = []
  for (const [key, value] of Object.entries(changes as Record<string, unknown>)) {
    if (!value || typeof value !== 'object') continue
    const pair = value as { old?: unknown; new?: unknown }
    const labelKey = `people.audit.gatewaySetting.${key}`
    const translated = translate(labelKey)
    lines.push(
      translate('people.audit.changeLine', {
        label: translated === labelKey ? key : translated,
        old: formatAuditValue(key, pair.old, translate),
        new: formatAuditValue(key, pair.new, translate),
      })
    )
  }

  return lines.join(' ')
}

function formatAuditValue(key: string, value: unknown, translate: Translate): string {
  if (value === null || value === undefined || value === '') return translate('people.audit.none')
  if (
    BOOLEAN_SETTINGS.has(key) &&
    (value === '0' ||
      value === '1' ||
      value === 0 ||
      value === 1 ||
      value === true ||
      value === false)
  ) {
    const on = value === '1' || value === 1 || value === true
    return translate(on ? 'people.audit.on' : 'people.audit.off')
  }
  if (typeof value === 'object') {
    const parts = Object.entries(value as Record<string, unknown>)
      .filter((entry): entry is [string, string] => entry[0] !== '' && typeof entry[1] === 'string')
      .map(([from, to]) => `${from} = ${to}`)
    return parts.length > 0 ? parts.join(', ') : translate('people.audit.none')
  }
  return String(value)
}
