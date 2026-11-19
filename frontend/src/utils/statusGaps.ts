import type { Feature, ServiceReadiness } from '@/services/featuresService'
import { idsCoveredByServices, serviceNeedsAttention } from '@/utils/serviceReadiness'

export interface StatusGap {
  id: string
  name: string
  kind: 'critical' | 'optional'
  /** Element id to scroll to. A feature also names the accordion to open. */
  anchor: string
  category: string | null
  service: ServiceReadiness | null
}

/**
 * What the status card should name. A health check that failed is critical.
 * A service that was never switched on is optional. A row the service list
 * already explains is not repeated.
 */
export function statusGaps(
  features: Record<string, Feature>,
  services: ServiceReadiness[] | undefined
): StatusGap[] {
  const covered = idsCoveredByServices(services)
  const gaps: StatusGap[] = []

  for (const service of services ?? []) {
    if (!serviceNeedsAttention(service) && service.state !== 'off') continue
    gaps.push({
      id: service.id,
      name: service.name,
      kind: serviceNeedsAttention(service) ? 'critical' : 'optional',
      anchor: `service-${service.id}`,
      category: null,
      service,
    })
  }

  for (const feature of Object.values(features)) {
    if (feature.status === 'active' || feature.status === 'healthy') continue
    if (covered.has(feature.id)) continue
    gaps.push({
      id: feature.id,
      name: feature.name,
      kind: feature.status === 'unhealthy' ? 'critical' : 'optional',
      anchor: `feature-${feature.id}`,
      category: feature.category || 'Other',
      service: null,
    })
  }

  return gaps.sort(
    (a, b) =>
      Number(a.kind === 'optional') - Number(b.kind === 'optional') || a.name.localeCompare(b.name)
  )
}
