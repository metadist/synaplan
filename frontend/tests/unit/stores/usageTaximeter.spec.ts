import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Control the gating dependencies deterministically (no runtime config / no
// real auth store) so the store logic can be exercised in isolation.
let mockAuthed = true
let mockEnabled = true

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAuthenticated() {
      return mockAuthed
    },
  }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    usageTaximeter: {
      get enabled() {
        return mockEnabled
      },
    },
  }),
}))

const getUsageSummary = vi.fn()
vi.mock('@/api/usageApi', () => ({
  getUsageSummary: () => getUsageSummary(),
}))

import { useUsageTaximeterStore, type MessageUsage } from '@/stores/usageTaximeter'

function usage(modelKey: string, cost: string, tokens = 100, kind = 'LLM'): MessageUsage {
  return {
    modelKey,
    cost,
    kind,
    promptTokens: Math.floor(tokens / 2),
    completionTokens: Math.ceil(tokens / 2),
    totalTokens: tokens,
  }
}

describe('usageTaximeter store', () => {
  beforeEach(() => {
    mockAuthed = true
    mockEnabled = true
    getUsageSummary.mockReset()
    setActivePinia(createPinia())
  })

  describe('active gate', () => {
    it('is true only when authenticated and the admin switch is on', () => {
      mockAuthed = true
      mockEnabled = true
      expect(useUsageTaximeterStore().active).toBe(true)
    })

    it('is false when the admin switch is off', () => {
      mockEnabled = false
      expect(useUsageTaximeterStore().active).toBe(false)
    })

    it('is false for an unauthenticated (guest) user', () => {
      mockAuthed = false
      expect(useUsageTaximeterStore().active).toBe(false)
    })
  })

  describe('applyComplete', () => {
    it('accumulates cost and tokens per model', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(usage('openai:gpt-4o', '0.02', 100), {
        todayCost: '0.02',
        todayTokens: 100,
      })
      store.applyComplete(usage('openai:gpt-4o', '0.03', 50), {
        todayCost: '0.05',
        todayTokens: 150,
      })

      expect(store.models).toHaveLength(1)
      expect(store.models[0].modelKey).toBe('openai:gpt-4o')
      expect(store.models[0].cost).toBeCloseTo(0.05, 6)
      expect(store.models[0].tokens).toBe(150)
      expect(store.todayTokens).toBe(150)
    })

    it('puts the active (most recent) model first in sortedModels', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(usage('openai:gpt-4o', '0.02'), null)
      store.applyComplete(usage('anthropic:claude', '0.01'), null)

      expect(store.sortedModels[0].modelKey).toBe('anthropic:claude')
    })

    it('counts model changes and toggles the epoch tone a→b→a', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(usage('openai:gpt-4o', '0.01'), null)
      expect(store.modelChanges).toBe(0)
      expect(store.epochs[0].tone).toBe('a')

      store.applyComplete(usage('anthropic:claude', '0.01'), null)
      expect(store.modelChanges).toBe(1)
      expect(store.epochs[1].tone).toBe('b')

      store.applyComplete(usage('openai:gpt-4o', '0.01'), null)
      expect(store.modelChanges).toBe(2)
      expect(store.epochs[2].tone).toBe('a')
    })

    it('does not create a new epoch when the same model answers again', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(usage('openai:gpt-4o', '0.01'), null)
      store.applyComplete(usage('openai:gpt-4o', '0.02'), null)

      expect(store.epochs).toHaveLength(1)
      expect(store.epochs[0].cost).toBeCloseTo(0.03, 6)
      expect(store.modelChanges).toBe(0)
    })
  })

  describe('money scale', () => {
    it('starts at 5 € and does not rescale below 95 % fill', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(null, { todayCost: '4.74', todayTokens: 1 })

      expect(store.scale).toBe(5)
      expect(store.justRescaled).toBe(false)
      expect(store.fill).toBeCloseTo(0.948, 3)
    })

    it('doubles the scale at 95 % fill and jumps to half fill', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(null, { todayCost: '4.75', todayTokens: 1 })

      expect(store.scale).toBe(10)
      expect(store.justRescaled).toBe(true)
      expect(store.fill).toBeCloseTo(0.475, 3)
    })

    it('rescales in one step for a large jump', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(null, { todayCost: '22', todayTokens: 1 })

      expect(store.scale).toBe(40)
      expect(store.justRescaled).toBe(true)
    })

    it('clearRescaleFlag resets the transition-suppression flag', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(null, { todayCost: '4.75', todayTokens: 1 })
      expect(store.justRescaled).toBe(true)

      store.clearRescaleFlag()
      expect(store.justRescaled).toBe(false)
    })
  })

  describe('loadSummary', () => {
    it('seeds day totals and derives the scale fresh (day reset → 5 €)', async () => {
      getUsageSummary.mockResolvedValue({ todayCost: '0.30', todayTokens: 42 })
      const store = useUsageTaximeterStore()

      await store.loadSummary()

      expect(store.todayCost).toBeCloseTo(0.3, 6)
      expect(store.todayTokens).toBe(42)
      expect(store.scale).toBe(5)
    })

    it('is a no-op when the taximeter is inactive', async () => {
      mockEnabled = false
      const store = useUsageTaximeterStore()

      await store.loadSummary()

      expect(getUsageSummary).not.toHaveBeenCalled()
    })
  })

  describe('seedFromHistory', () => {
    it('rebuilds models, prompts, model changes and epochs from history', () => {
      const store = useUsageTaximeterStore()
      store.seedFromHistory([
        { role: 'user', timestamp: 1 },
        { role: 'assistant', usage: usage('openai:gpt-4o', '0.02', 80), timestamp: 2 },
        { role: 'user', timestamp: 3 },
        { role: 'assistant', usage: usage('anthropic:claude', '0.05', 120), timestamp: 4 },
      ])

      expect(store.promptCount).toBe(2)
      expect(store.models).toHaveLength(2)
      expect(store.modelChanges).toBe(1)
      expect(store.epochs.map((e) => e.tone)).toEqual(['a', 'b'])
    })

    it('resets the session on a chat switch but leaves day totals untouched', () => {
      const store = useUsageTaximeterStore()
      store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.50', todayTokens: 500 })
      expect(store.todayCost).toBeCloseTo(1.5, 6)

      store.seedFromHistory([{ role: 'user', timestamp: 1 }])

      expect(store.promptCount).toBe(1)
      expect(store.models).toHaveLength(0)
      expect(store.epochs).toHaveLength(0)
      // Day totals are the server's truth — a chat switch must not clear them.
      expect(store.todayCost).toBeCloseTo(1.5, 6)
      expect(store.todayTokens).toBe(500)
    })
  })

  describe('registerPrompt', () => {
    it('increments only the prompt counter', () => {
      const store = useUsageTaximeterStore()
      store.registerPrompt()
      store.registerPrompt()

      expect(store.promptCount).toBe(2)
      expect(store.models).toHaveLength(0)
      expect(store.modelChanges).toBe(0)
    })
  })

  describe('epochSegments', () => {
    it('emits a base (tone a) segment for pre-session spend then the epochs', () => {
      const store = useUsageTaximeterStore()
      // Day already had 1.00 € before this session; the session then spends
      // 0.02 € on one model.
      store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })

      const segs = store.epochSegments
      // base (1.00) + one epoch (0.02), both tone 'a', as fractions of scale 5.
      expect(segs).toHaveLength(2)
      expect(segs[0].tone).toBe('a')
      expect(segs[0].ratio).toBeCloseTo(1.0 / 5, 4)
      expect(segs[1].ratio).toBeCloseTo(0.02 / 5, 4)
    })
  })
})
