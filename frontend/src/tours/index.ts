import type { TourDefinition } from './types'

const definitions: TourDefinition[] = []

const registry = new Map(definitions.map((tour) => [tour.id, tour]))

export function getTour(id: string): TourDefinition | undefined {
  return registry.get(id)
}

export function tourIds(): string[] {
  return [...registry.keys()]
}
