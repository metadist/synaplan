import type { McpToolPermission, ToolPermissionChoice } from '@/services/api/mcpServersApi'

export type ToolOutcome = 'auto' | 'approve' | 'block'
export type ToolCategory = 'read' | 'write' | 'destructive' | 'unavailable'

export const OUTCOMES: ToolOutcome[] = ['auto', 'approve', 'block']
export const CATEGORIES: ToolCategory[] = ['read', 'write', 'destructive', 'unavailable']

/** Tools the owner may change: a locked tool keeps what applies. */
export const editableTools = (tools: McpToolPermission[]): McpToolPermission[] =>
  tools.filter((tool) => tool.locked_reason === null)

/** The one choice every editable tool of a category shares, or `mixed`. */
export const categoryOutcome = (tools: McpToolPermission[]): ToolOutcome | 'mixed' => {
  const editable = editableTools(tools)
  const first = editable[0]?.outcome
  return first !== undefined && editable.every((tool) => tool.outcome === first) ? first : 'mixed'
}

/**
 * What to send to restore a tool: `default` when it followed its category
 * default, so Undo does not pin a value the default would have given anyway.
 */
export const restoreChoice = (tool: McpToolPermission): ToolPermissionChoice =>
  tool.outcome === tool.default_outcome ? 'default' : tool.outcome
