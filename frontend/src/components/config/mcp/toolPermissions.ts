import type { McpToolPermission } from '@/services/api/mcpServersApi'

export type ToolOutcome = 'auto' | 'approve' | 'block'
export type ToolCategory = 'read' | 'write' | 'destructive' | 'unavailable'

export const OUTCOMES: ToolOutcome[] = ['auto', 'approve', 'block']
export const CATEGORIES: ToolCategory[] = ['read', 'write', 'destructive', 'unavailable']

/** The one choice every tool of a category shares, or `mixed`. */
export const categoryOutcome = (tools: McpToolPermission[]): ToolOutcome | 'mixed' => {
  const first = tools[0]?.outcome
  return first !== undefined && tools.every((tool) => tool.outcome === first) ? first : 'mixed'
}
