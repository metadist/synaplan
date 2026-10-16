import { describe, expect, it } from 'vitest'
import { gatewayAuditDetail } from '@/utils/auditGatewayChanges'

const labels: Record<string, string> = {
  'people.audit.on': 'On',
  'people.audit.off': 'Off',
  'people.audit.none': 'none',
  'people.audit.changeLine': '{label}: {old} → {new}',
  'people.audit.gatewaySetting.ENABLED': 'Gateway',
  'people.audit.gatewaySetting.UPSTREAM_URL': 'Gateway address',
  'people.audit.gatewaySetting.MODEL_ALIASES': 'Model aliases',
}

function translate(key: string, values?: Record<string, string>): string {
  const template = labels[key] ?? key
  if (!values) return template
  return template.replace(/\{(\w+)\}/g, (_, name: string) => values[name] ?? '')
}

describe('gatewayAuditDetail', () => {
  it('ignores rows that are not gateway changes', () => {
    expect(
      gatewayAuditDetail('share.grant', { changes: { ENABLED: { old: '0', new: '1' } } }, translate)
    ).toBe('')
  })

  it('shows the old and new values with readable labels', () => {
    const detail = gatewayAuditDetail(
      'messages_gateway.flags',
      { changes: { ENABLED: { old: '0', new: '1' } } },
      translate
    )
    expect(detail).toBe('Gateway: Off → On')
  })

  it('hides query secrets and lists alias maps', () => {
    const detail = gatewayAuditDetail(
      'messages_gateway.aliases',
      {
        changes: {
          MODEL_ALIASES: { old: { 'claude-3': 'old-model' }, new: { 'claude-3': 'new-model' } },
        },
      },
      translate
    )
    expect(detail).toBe('Model aliases: claude-3 = old-model → claude-3 = new-model')
  })
})
