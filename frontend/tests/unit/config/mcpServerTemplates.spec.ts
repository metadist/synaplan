import { describe, expect, it } from 'vitest'
import {
  MCP_CUSTOM_TEMPLATE,
  findMcpServerTemplate,
  nextMcpServerTemplate,
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
})
