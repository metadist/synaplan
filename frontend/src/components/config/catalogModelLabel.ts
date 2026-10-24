export type CatalogNameRow = {
  name: string
  service: string
  json?: Record<string, unknown> | null
}

/** Two rows with the same name show the provider and the endpoint. */
export function catalogModelLabel(model: CatalogNameRow, rows: CatalogNameRow[]): string {
  const twins = rows.filter((row) => row.name === model.name)
  if (twins.length < 2) {
    return model.name
  }
  const endpoint = endpointOf(model)
  return endpoint !== ''
    ? `${model.name} · ${model.service} · ${endpoint}`
    : `${model.name} · ${model.service}`
}

export function isWithdrawnModel(model: CatalogNameRow): boolean {
  const meta = record(model.json?.meta)
  const imported = record(meta?.import)
  return true === imported?.withdrawn
}

function endpointOf(model: CatalogNameRow): string {
  const endpoint = model.json?.endpoint
  if (typeof endpoint === 'string' && endpoint.trim() !== '') {
    return endpoint.trim()
  }
  const meta = record(model.json?.meta)
  const imported = record(meta?.import)
  return typeof imported?.source === 'string' ? imported.source : ''
}

function record(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null
}
