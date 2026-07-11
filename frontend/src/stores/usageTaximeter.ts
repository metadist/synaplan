import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getUsageSummary } from '@/api/usageApi'
import { useConfigStore } from '@/stores/config'
import { useAuthStore } from '@/stores/auth'

/**
 * Per-message usage payload, shared shape between the SSE `complete` event and
 * the persisted history row (see backend MessageApiFormatter / StreamController).
 * `cost` is the CHARGED amount (provider cost + markup) as a decimal string, or
 * null when it was never recorded (e.g. a non-web channel).
 */
export interface MessageUsage {
  promptTokens: number
  completionTokens: number
  totalTokens: number
  cost: string | null
  modelKey: string
  kind: string
}

/** Live daily totals delivered with the SSE `complete` event (`usage_totals`). */
export interface UsageTotals {
  todayCost: string
  todayTokens: number
}

/** A minimal structural view of a chat message for session reconstruction. */
export interface UsageSessionMessage {
  role: 'user' | 'assistant'
  usage?: MessageUsage | null
  /** Auxiliary usage of the turn (sorting/routing call, media renders, TTS). */
  usageExtra?: MessageUsage[] | null
  timestamp?: Date | number
}

/** Alternating epoch tone; toggles on every in-session model change. */
export type EpochTone = 'a' | 'b'

interface SessionModel {
  modelKey: string
  label: string
  kind: string
  cost: number
  tokens: number
  lastUsedAt: number
  tone: EpochTone
}

interface Epoch {
  tone: EpochTone
  cost: number
}

/**
 * Money scale base: the bar/ring is "full" at 5,00 €. When the fill reaches
 * 95 % the scale doubles (5 → 10 → 20 → 40 …). Percentages never surface in the
 * UI — the scale and fill are internal quantities only.
 */
export const SCALE_BASE_EUR = 5
const RESCALE_THRESHOLD = 0.95

/** Parse a decimal-string / number cost into a finite number (0 on garbage). */
function parseCost(raw: string | number | null | undefined): number {
  if (raw === null || raw === undefined) return 0
  const n = typeof raw === 'number' ? raw : Number.parseFloat(raw)
  return Number.isFinite(n) && n > 0 ? n : 0
}

/** Human label from a "provider:model" key (strip the provider prefix). */
function labelFromModelKey(key: string): string {
  const idx = key.indexOf(':')
  return idx >= 0 ? key.slice(idx + 1) : key
}

function toggleTone(tone: EpochTone): EpochTone {
  return tone === 'a' ? 'b' : 'a'
}

function toEpochMs(ts: Date | number | undefined, fallback: number): number {
  if (ts instanceof Date) return ts.getTime()
  if (typeof ts === 'number') return ts
  return fallback
}

export const useUsageTaximeterStore = defineStore('usageTaximeter', () => {
  const configStore = useConfigStore()
  const authStore = useAuthStore()

  // --- Day totals (server truth) -------------------------------------------
  const todayCost = ref(0)
  const todayTokens = ref(0)

  // --- Money scale ----------------------------------------------------------
  const scale = ref(SCALE_BASE_EUR)
  // True for exactly one render frame after a scale jump so the consumer can
  // suppress the height transition (the jump to half-fill is meant to be abrupt).
  const justRescaled = ref(false)

  // --- Session state (per active chat) -------------------------------------
  const models = ref<SessionModel[]>([])
  const promptCount = ref(0)
  const modelChanges = ref(0)
  const epochs = ref<Epoch[]>([])
  // Internal: model key of the most recent assistant usage (change detection).
  const lastModelKey = ref<string | null>(null)

  /**
   * Whether the taximeter should be shown/active: an authenticated web user
   * (never guest / widget) AND the admin master switch is on. Defined ONCE
   * here so every consumer (bar, ring, badge, ChatView) shares one condition.
   */
  const active = computed(() => authStore.isAuthenticated && configStore.usageTaximeter.enabled)

  /** Internal fill ratio (may momentarily exceed the threshold before rescale). */
  const fillRatio = computed(() => (scale.value > 0 ? todayCost.value / scale.value : 0))
  /** Clamped fill for rendering the bar/ring [0..1]. */
  const fill = computed(() => Math.min(1, Math.max(0, fillRatio.value)))

  /** Total cost accumulated across the current session's model runs. */
  const sessionCost = computed(() => epochs.value.reduce((sum, e) => sum + e.cost, 0))

  /**
   * Stacked segments (bottom → top) for the bar/ring, as fractions of the
   * scale. The pre-session base (today's spend that happened before this chat,
   * e.g. other chats/devices) renders first in tone A, then the session epochs.
   */
  const epochSegments = computed<Array<{ tone: EpochTone; ratio: number }>>(() => {
    const s = scale.value || SCALE_BASE_EUR
    const base = Math.max(0, todayCost.value - sessionCost.value)
    const segments: Array<{ tone: EpochTone; ratio: number }> = []
    if (base > 0) {
      segments.push({ tone: 'a', ratio: Math.min(1, base / s) })
    }
    for (const epoch of epochs.value) {
      segments.push({ tone: epoch.tone, ratio: Math.min(1, epoch.cost / s) })
    }
    return segments
  })

  /** Used models, active (most recently used) first, then by recency. */
  const sortedModels = computed<SessionModel[]>(() => {
    return [...models.value].sort((a, b) => {
      if (a.modelKey === lastModelKey.value) return -1
      if (b.modelKey === lastModelKey.value) return 1
      return b.lastUsedAt - a.lastUsedAt
    })
  })

  // --- Scale helpers --------------------------------------------------------

  /** Derive the smallest 5·2ⁿ scale with fill < 0.95, starting fresh from base. */
  function deriveScaleFromToday(): void {
    let s = SCALE_BASE_EUR
    while (todayCost.value / s >= RESCALE_THRESHOLD) {
      s *= 2
    }
    scale.value = s
  }

  /** Grow the scale during live updates, flagging a jump so the transition pauses. */
  function growScaleForLiveUpdate(): void {
    let jumped = false
    while (todayCost.value / scale.value >= RESCALE_THRESHOLD) {
      scale.value *= 2
      jumped = true
    }
    if (jumped) {
      justRescaled.value = true
    }
  }

  /** Consumer calls this after applying one render with the transition suppressed. */
  function clearRescaleFlag(): void {
    justRescaled.value = false
  }

  // --- Session ingestion ----------------------------------------------------

  /**
   * Fold one assistant usage record into the session state: model totals,
   * epoch/tone tracking and model-change counting. Shared by the live
   * `complete` path and history reconstruction.
   */
  function ingestUsage(usage: MessageUsage, atMs: number): void {
    const cost = parseCost(usage.cost)
    const key = usage.modelKey || 'unknown'

    let tone: EpochTone
    if (lastModelKey.value === null) {
      tone = 'a'
      epochs.value.push({ tone, cost })
    } else if (lastModelKey.value !== key) {
      modelChanges.value += 1
      const prevTone = epochs.value.length > 0 ? epochs.value[epochs.value.length - 1].tone : 'a'
      tone = toggleTone(prevTone)
      epochs.value.push({ tone, cost })
    } else {
      tone = epochs.value.length > 0 ? epochs.value[epochs.value.length - 1].tone : 'a'
      if (epochs.value.length > 0) {
        epochs.value[epochs.value.length - 1].cost += cost
      } else {
        epochs.value.push({ tone, cost })
      }
    }
    lastModelKey.value = key

    const existing = models.value.find((m) => m.modelKey === key)
    if (existing) {
      existing.cost += cost
      existing.tokens += usage.totalTokens
      existing.lastUsedAt = atMs
      existing.tone = tone
    } else {
      models.value.push({
        modelKey: key,
        label: labelFromModelKey(key),
        kind: usage.kind || 'LLM',
        cost,
        tokens: usage.totalTokens,
        lastUsedAt: atMs,
        tone,
      })
    }
  }

  /**
   * Fold an auxiliary usage record (sorting/routing call, media render, TTS)
   * into the session model list ONLY. Aux usage never participates in the
   * epoch/tone tracking — the bar segments follow the chat LLM epochs, while
   * aux costs are still visible per model in the stats panel (and are part of
   * the server-side day totals anyway).
   */
  function ingestAuxUsage(usage: MessageUsage, atMs: number): void {
    const cost = parseCost(usage.cost)
    const key = usage.modelKey || 'unknown'

    const existing = models.value.find((m) => m.modelKey === key)
    if (existing) {
      existing.cost += cost
      existing.tokens += usage.totalTokens
      existing.lastUsedAt = atMs
    } else {
      models.value.push({
        modelKey: key,
        label: labelFromModelKey(key),
        kind: usage.kind || 'LLM',
        cost,
        tokens: usage.totalTokens,
        lastUsedAt: atMs,
        tone: 'a',
      })
    }
  }

  // --- Public actions -------------------------------------------------------

  /** Reset the per-session accumulators (day totals are left untouched). */
  function resetSession(): void {
    models.value = []
    promptCount.value = 0
    modelChanges.value = 0
    epochs.value = []
    lastModelKey.value = null
  }

  /** Apply server-authoritative day totals (from summary or `usage_totals`). */
  function setDayTotals(cost: number, tokens: number, live: boolean): void {
    todayCost.value = Math.max(0, cost)
    todayTokens.value = Math.max(0, tokens)
    if (live) {
      growScaleForLiveUpdate()
    } else {
      deriveScaleFromToday()
    }
  }

  /** One-shot daily-total seed on chat-view mount (charged today spend). */
  async function loadSummary(): Promise<void> {
    if (!active.value) return
    try {
      const summary = await getUsageSummary()
      setDayTotals(parseCost(summary.todayCost), summary.todayTokens ?? 0, false)
    } catch {
      // Non-fatal: the display simply starts empty; the next `complete` event
      // carries fresh `usage_totals` anyway.
    }
  }

  // Timers of a pending post-completion settlement refresh (see below).
  let settlementTimers: Array<ReturnType<typeof setTimeout>> = []

  /**
   * Refresh the display after an async media job completed — resilient to the
   * worker's write ordering: the job becomes visible as "done" (Redis) BEFORE
   * the billing row and the message usage meta are committed, so a poll-driven
   * completion can observe "done" seconds early. Refresh immediately AND retry
   * twice with a short backoff so the settled figures always land. Not
   * polling — a bounded, self-clearing follow-up.
   *
   * @param reconcile optional callback that re-fetches the persisted message
   *                  and re-seeds the session model list from history
   */
  function refreshAfterSettlement(reconcile?: () => void): void {
    if (!active.value) return

    for (const timer of settlementTimers) clearTimeout(timer)
    settlementTimers = []

    const run = () => {
      void loadSummary()
      reconcile?.()
    }

    run()
    for (const delayMs of [2500, 7000]) {
      settlementTimers.push(setTimeout(run, delayMs))
    }
  }

  /**
   * Rebuild the session from the loaded chat history (chat switch / reload).
   * Resets the session first, then folds in every assistant `usage` and counts
   * user prompts. Day totals are NOT touched (they come from the server).
   */
  function seedFromHistory(messages: UsageSessionMessage[]): void {
    resetSession()
    let order = 0
    for (const message of messages) {
      order += 1
      if (message.role === 'user') {
        promptCount.value += 1
      } else if (message.role === 'assistant') {
        const atMs = toEpochMs(message.timestamp, order)
        if (message.usage) {
          ingestUsage(message.usage, atMs)
        }
        for (const extra of message.usageExtra ?? []) {
          ingestAuxUsage(extra, atMs)
        }
      }
    }
  }

  /** Live update after a completed answer (`complete` SSE event). */
  function applyComplete(
    usage?: MessageUsage | null,
    totals?: UsageTotals | null,
    extra?: MessageUsage[] | null
  ): void {
    if (totals) {
      setDayTotals(parseCost(totals.todayCost), totals.todayTokens ?? 0, true)
    }
    if (usage) {
      ingestUsage(usage, Date.now())
    }
    for (const entry of extra ?? []) {
      ingestAuxUsage(entry, Date.now())
    }
  }

  /** Count a user prompt (called when the user sends a message). */
  function registerPrompt(): void {
    promptCount.value += 1
  }

  return {
    // day totals
    todayCost,
    todayTokens,
    // scale
    scale,
    fill,
    justRescaled,
    clearRescaleFlag,
    // session
    models,
    promptCount,
    modelChanges,
    epochs,
    sessionCost,
    epochSegments,
    sortedModels,
    // gating
    active,
    // actions
    loadSummary,
    refreshAfterSettlement,
    seedFromHistory,
    applyComplete,
    registerPrompt,
    resetSession,
  }
})
