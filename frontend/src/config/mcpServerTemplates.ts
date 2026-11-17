/**
 * Optional starting points when adding an MCP server.
 *
 * Custom is the real product: any Streamable HTTP MCP endpoint.
 * Named templates only prefill the form (name, auth header, write default)
 * and can be cleared again — they are not exclusive integrations.
 */
export type McpAuthMode = 'none' | 'bearer' | 'oauth'

export interface McpServerTemplate {
  key: string
  icon: string
  /** Prefill for the display name; empty for the custom (blank) form. */
  name: string
  authHeader: string
  /** Default `bearer` — static header. `oauth` templates hide the token fields. */
  authMode?: McpAuthMode
  /** Locked Streamable HTTP URL for hosted OAuth servers. */
  urlPrefill?: string
}

export const MCP_CUSTOM_TEMPLATE = 'custom'

export const mcpServerTemplates: McpServerTemplate[] = [
  {
    key: MCP_CUSTOM_TEMPLATE,
    icon: 'heroicons:puzzle-piece',
    name: '',
    authHeader: '',
  },
  {
    key: 'github',
    icon: 'simple-icons:github',
    name: 'GitHub',
    authHeader: 'Authorization',
  },
  {
    key: 'n8n',
    icon: 'simple-icons:n8n',
    name: 'n8n',
    authHeader: 'Authorization',
  },
  {
    key: 'atlassian',
    icon: 'simple-icons:atlassian',
    name: 'Jira & Confluence',
    authHeader: '',
    authMode: 'oauth',
    urlPrefill: 'https://mcp.atlassian.com/v2/mcp',
  },
  {
    key: 'notion',
    icon: 'simple-icons:notion',
    name: 'Notion',
    authHeader: '',
    authMode: 'oauth',
    urlPrefill: 'https://mcp.notion.com/mcp',
  },
  {
    key: 'higgsfield',
    icon: 'heroicons:sparkles',
    name: 'Higgsfield',
    authHeader: '',
    authMode: 'oauth',
    urlPrefill: 'https://mcp.higgsfield.ai/mcp',
  },
]

export function visibleMcpServerTemplates(oauthEnabled: boolean): McpServerTemplate[] {
  return mcpServerTemplates.filter((template) => template.authMode !== 'oauth' || oauthEnabled)
}

export function isOAuthTemplate(template: McpServerTemplate): boolean {
  return template.authMode === 'oauth'
}

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
