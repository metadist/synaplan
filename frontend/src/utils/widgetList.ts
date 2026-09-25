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
