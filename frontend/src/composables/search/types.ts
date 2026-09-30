import type { Component } from 'vue'

export type SearchKind =
  | 'best'
  | 'command'
  | 'page'
  | 'setting'
  | 'chat'
  | 'file'
  | 'memory'
  | 'widget'
  | 'assistant'
  | 'task'
  | 'ask'

export type MatchSource = 'local' | 'lexical' | 'semantic' | 'both'

/**
 * Inline control for a setting result. Writes always go through the
 * existing settings endpoints; the descriptor only says what to render.
 */
export interface SettingControl {
  key: string
  type: 'toggle' | 'select' | 'navigate'
  scope: 'user' | 'system'
  current: string | boolean | null
  options: Array<{ value: string; label: string }>
  envPinned: boolean
  consequence: string | null
}

export interface SearchResult {
  /** Stable, unique across all sources (`page:/files`, `chat:12`, …). */
  id: string
  kind: SearchKind
  title: string
  /** Where the thing lives, e.g. "Manage › Channels". */
  subtitle?: string
  snippet?: string
  icon: Component
  matchedBy: MatchSource
  /** Navigation target; used when `run` is absent. */
  route?: string
  run?: () => unknown
  setting?: SettingControl
  score?: number
}

export interface SearchGroup {
  /** A result kind, or `recent` / `suggested` for the empty-query view. */
  key: string
  label: string
  items: SearchResult[]
}

/** A document for the in-browser MiniSearch index. */
export interface LocalSearchDoc {
  id: string
  title: string
  keywords: string
  subtitle: string
}
