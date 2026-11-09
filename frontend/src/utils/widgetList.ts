export function listedWidgets<T extends { id: number; widgetId?: string }>(
  owned: T[],
  shared: T[],
  onlyShared: boolean
): T[] {
  if (onlyShared) return shared
  const ownedKeys = new Set(owned.map((widget) => widget.widgetId || String(widget.id)))
  return [
    ...owned,
    ...shared.filter((widget) => !ownedKeys.has(widget.widgetId || String(widget.id))),
  ]
}

export function mapSharedWidgetItem(item: {
  id: string
  name: string
  permission: string
  ownerName?: string | null
  sharedVia?: { type: 'user' | 'group' | 'everyone'; name: string } | null
  meta?: Record<string, unknown>
}) {
  return {
    id: Number(item.id),
    widgetId: String(item.meta?.widgetId ?? ''),
    name: item.name,
    taskPromptTopic: '',
    status: 'active' as const,
    config: {},
    isActive: true,
    created: 0,
    updated: 0,
    shared: true,
    access: item.permission,
    ownerName: item.ownerName ?? undefined,
    sharedVia: item.sharedVia ?? null,
  }
}

export function wizardAgentIdFromQuery(query: Record<string, unknown>): number | null {
  const id = Number(query.agentId)
  return Number.isInteger(id) && id > 0 ? id : null
}

export function queryWithoutWizardParams<T extends Record<string, unknown>>(query: T): T {
  const next = { ...query }
  delete next.new
  delete next.agentId
  return next
}
