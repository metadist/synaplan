import type { AIModel } from '@/types/ai-models'

export type CostTier = 'low' | 'mid' | 'high' | 'free' | 'unknown'

export interface CostTierInfo {
  tier: CostTier
  label: string
  class: string
}

const TIER_CONFIG: Record<CostTier, { label: string; class: string }> = {
  free: {
    label: 'models.costTier.free',
    class: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  },
  unknown: {
    label: 'models.costTier.unknown',
    class: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  },
  low: {
    label: 'models.costTier.low',
    class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400',
  },
  mid: {
    label: 'models.costTier.mid',
    class: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
  },
  high: {
    label: 'models.costTier.high',
    class: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
  },
}

function getAverageCost(model: AIModel): number {
  return ((model.priceIn ?? 0) + (model.priceOut ?? 0)) / 2
}

function computeMedian(sorted: number[]): number {
  if (sorted.length === 0) return 0
  const mid = Math.floor(sorted.length / 2)
  if (sorted.length % 2 === 0) {
    return ((sorted[mid - 1] ?? 0) + (sorted[mid] ?? 0)) / 2
  }
  return sorted[mid] ?? 0
}

const peersCache = new WeakMap<readonly AIModel[], Map<string, number>>()

function modelCost(model: AIModel, capability?: string): number {
  if (capability === 'TEXT2PIC' || capability === 'PIC2PIC') {
    const priced = model.capabilityPrices?.[capability]
    if (typeof priced === 'number') return priced
  }
  return getAverageCost(model)
}

function getMedianForPeers(peers: readonly AIModel[], capability?: string): number {
  const key = capability ?? ''
  const byCapability = peersCache.get(peers) ?? new Map<string, number>()
  if (!peersCache.has(peers)) peersCache.set(peers, byCapability)
  const cached = byCapability.get(key)
  if (cached !== undefined) return cached

  const costs = peers
    .map((peer) => modelCost(peer, capability))
    .filter((c) => c > 0)
    .sort((a, b) => a - b)

  const median = computeMedian(costs)
  byCapability.set(key, median)
  return median
}

/**
 * Classify a model's cost tier relative to its peers.
 * Peers should be the models shown in the same dropdown.
 * Image dropdowns pass the capability so the badge uses the billed per-image price.
 */
export function getCostTier(
  model: AIModel,
  peers: readonly AIModel[],
  capability?: string
): CostTierInfo {
  const avgCost = modelCost(model, capability)

  if (model.priceKnown === false && avgCost <= 0) {
    return { ...TIER_CONFIG.unknown, tier: 'unknown' }
  }

  if (avgCost <= 0) return { ...TIER_CONFIG.free, tier: 'free' }

  const median = getMedianForPeers(peers, capability)

  if (median <= 0) return { ...TIER_CONFIG.mid, tier: 'mid' }

  let tier: CostTier
  if (avgCost <= median * 0.5) {
    tier = 'low'
  } else if (avgCost <= median * 2) {
    tier = 'mid'
  } else {
    tier = 'high'
  }

  return { tier, ...TIER_CONFIG[tier] }
}
