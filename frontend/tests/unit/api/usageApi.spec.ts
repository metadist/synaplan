import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { UsageStats } from '@/api/usageApi'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    apiBaseUrl: '/api',
  }),
}))

describe('UsageStats interface', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should accept a valid full stats object', () => {
    const stats: UsageStats = {
      user_level: 'PRO',
      phone_verified: true,
      subscription: {
        level: 'PRO',
        active: true,
        plan_name: 'Pro Plan',
        expires_at: null,
        stripe_customer_id: null,
      },
      usage: {
        messages: {
          used: 10,
          limit: 100,
          remaining: 90,
          allowed: true,
          resets_at: null,
          type: 'monthly',
        },
      },
      limits: { messages: 100 },
      remaining: { messages: 90 },
      breakdown: {
        by_source: {
          WEB: { total: 10, actions: { messages: 10 } },
        },
        by_time: {
          today: { total: 5, actions: { messages: 5 } },
        },
      },
      recent_usage: [
        {
          timestamp: 1700000000,
          datetime: '2025-01-01 00:00:00',
          action: 'messages',
          source: 'WEB',
          model: 'gpt-4o',
          tokens: 500,
          prompt_tokens: 300,
          completion_tokens: 200,
          cached_tokens: 50,
          cache_creation_tokens: 10,
          estimated: false,
          cost: 0.0015,
          latency: 1200,
          status: 'success',
        },
      ],
      total_requests: 10,
      cost_budget: {
        used: 2.5,
        budget: 15.0,
        remaining: 12.5,
        percent: 16.67,
        period_start: 1700000000,
        period_end: 1702600000,
      },
      cost_summary: {
        today: 0.05,
        this_week: 1.2,
        this_month: 2.5,
        cache_savings: 0.01,
      },
    }

    expect(stats.user_level).toBe('PRO')
    expect(stats.cost_budget.percent).toBeCloseTo(16.67)
    expect(stats.cost_summary.today).toBe(0.05)
    expect(stats.recent_usage[0].prompt_tokens).toBe(300)
    expect(stats.recent_usage[0].cached_tokens).toBe(50)
    expect(stats.recent_usage[0].estimated).toBe(false)
    expect(stats.recent_usage[0].cost).toBe(0.0015)
  })

  it('should handle estimated tokens correctly', () => {
    const entry: UsageStats['recent_usage'][0] = {
      timestamp: 1700000000,
      datetime: '2025-01-01 00:00:00',
      action: 'messages',
      source: 'WEB',
      model: 'ollama-llama3',
      tokens: 500,
      prompt_tokens: 300,
      completion_tokens: 200,
      cached_tokens: 0,
      cache_creation_tokens: 0,
      estimated: true,
      cost: 0,
      latency: 800,
      status: 'success',
    }

    expect(entry.estimated).toBe(true)
    expect(entry.cost).toBe(0)
    expect(entry.cached_tokens).toBe(0)
  })

  it('should handle zero budget scenario', () => {
    const budget: UsageStats['cost_budget'] = {
      used: 0,
      budget: 0,
      remaining: 0,
      percent: 0,
      period_start: 1700000000,
      period_end: 1702600000,
    }

    expect(budget.budget).toBe(0)
    expect(budget.percent).toBe(0)
  })
})
