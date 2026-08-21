/**
 * Optional starting points when adding an MCP server.
 *
 * Custom is the real product: any Streamable HTTP MCP endpoint.
 * Named templates only prefill the form (name, auth header, write default)
 * and can be cleared again — they are not exclusive integrations.
 */
export interface McpServerTemplate {
  key: string
  icon: string
  /** Prefill for the display name; empty for the custom (blank) form. */
  name: string
  authHeader: string
  allowWrite: boolean
}

export const MCP_CUSTOM_TEMPLATE = 'custom'

export const mcpServerTemplates: McpServerTemplate[] = [
  {
    key: MCP_CUSTOM_TEMPLATE,
    icon: 'heroicons:puzzle-piece',
    name: '',
    authHeader: '',
    allowWrite: false,
  },
  {
    key: 'jira',
    icon: 'simple-icons:jira',
    name: 'Jira',
    authHeader: 'Authorization',
    allowWrite: true,
  },
  {
    key: 'confluence',
    icon: 'simple-icons:confluence',
    name: 'Confluence',
    authHeader: 'Authorization',
    allowWrite: true,
  },
  {
    key: 'github',
    icon: 'simple-icons:github',
    name: 'GitHub',
    authHeader: 'Authorization',
    allowWrite: false,
  },
  {
    key: 'n8n',
    icon: 'simple-icons:n8n',
    name: 'n8n',
    authHeader: 'Authorization',
    allowWrite: false,
  },
]

export function findMcpServerTemplate(key: string): McpServerTemplate {
  return mcpServerTemplates.find((template) => template.key === key) ?? mcpServerTemplates[0]
}

/**
 * Resolve the next template after a click. Clicking the already-selected
 * named template returns to Custom so a selection is never stuck on.
 */
export function nextMcpServerTemplate(currentKey: string, clickedKey: string): string {
  if (clickedKey !== MCP_CUSTOM_TEMPLATE && clickedKey === currentKey) {
    return MCP_CUSTOM_TEMPLATE
  }
  return clickedKey
}
