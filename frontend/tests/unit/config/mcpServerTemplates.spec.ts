import { describe, expect, it } from 'vitest'
import {
  MCP_CUSTOM_TEMPLATE,
  findMcpServerTemplate,
  isOAuthTemplate,
  nextMcpServerTemplate,
  visibleMcpServerTemplates,
} from '@/config/mcpServerTemplates'

describe('mcpServerTemplates', () => {
  it('treats Custom as the default template', () => {
    expect(findMcpServerTemplate('unknown').key).toBe(MCP_CUSTOM_TEMPLATE)
    expect(findMcpServerTemplate(MCP_CUSTOM_TEMPLATE).name).toBe('')
  })

  it('clears a named template when it is clicked again', () => {
    expect(nextMcpServerTemplate('jira', 'jira')).toBe(MCP_CUSTOM_TEMPLATE)
    expect(nextMcpServerTemplate('confluence', 'confluence')).toBe(MCP_CUSTOM_TEMPLATE)
  })

  it('does not clear Custom when it is already selected', () => {
    expect(nextMcpServerTemplate(MCP_CUSTOM_TEMPLATE, MCP_CUSTOM_TEMPLATE)).toBe(
      MCP_CUSTOM_TEMPLATE
    )
  })

  it('switches from one named template to another', () => {
    expect(nextMcpServerTemplate('jira', 'confluence')).toBe('confluence')
    expect(nextMcpServerTemplate(MCP_CUSTOM_TEMPLATE, 'github')).toBe('github')
  })

  it('hides OAuth templates until the administrator turns the flag on', () => {
    expect(visibleMcpServerTemplates(false).map((t) => t.key)).not.toContain('notion')
    expect(visibleMcpServerTemplates(false).map((t) => t.key)).not.toContain('higgsfield')
    expect(visibleMcpServerTemplates(true).map((t) => t.key)).toEqual(
      expect.arrayContaining(['notion', 'higgsfield', MCP_CUSTOM_TEMPLATE])
    )
  })

  it('locks Notion and Higgsfield to their hosted MCP URLs', () => {
    const notion = findMcpServerTemplate('notion')
    const higgsfield = findMcpServerTemplate('higgsfield')
    expect(isOAuthTemplate(notion)).toBe(true)
    expect(notion.urlPrefill).toBe('https://mcp.notion.com/mcp')
    expect(isOAuthTemplate(higgsfield)).toBe(true)
    expect(higgsfield.urlPrefill).toBe('https://mcp.higgsfield.ai/mcp')
  })
})
