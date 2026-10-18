// Review spike (2026-10-18) — not product code. Started by ../../run.sh
// (@modelcontextprotocol/server@2.3.1, @modelcontextprotocol/node@2.1.1, zod@4.6.7).
// MCP_LEGACY=stateless (the SDK default) serves both protocol eras;
// MCP_LEGACY=reject is a modern-only (2026-07-28) endpoint.
import { createServer } from 'node:http'
import { createMcpHandler, McpServer } from '@modelcontextprotocol/server'
import { toNodeHandler } from '@modelcontextprotocol/node'
import * as z from 'zod/v4'

const legacy = process.env.MCP_LEGACY === 'reject' ? 'reject' : 'stateless'
const port = Number(process.env.PORT ?? 8000)

const handler = createMcpHandler(
  (ctx) => {
    const server = new McpServer({ name: 'node-v2-tools', version: '1.0.0' })
    server.registerTool(
      'slugify',
      {
        description: 'Turn a title into a URL slug.',
        inputSchema: z.object({ text: z.string() }),
        annotations: { readOnlyHint: true },
      },
      async ({ text }) => {
        const slug = text
          .toLowerCase()
          .normalize('NFKD')
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
        return { content: [{ type: 'text', text: JSON.stringify({ slug, runtime: 'node-v2', era: ctx?.era ?? 'unknown' }) }] }
      }
    )
    return server
  },
  { legacy }
)

const node = toNodeHandler(handler)
createServer((req, res) => {
  if ((req.url ?? '').split('?')[0] === '/mcp') {
    node(req, res)
    return
  }
  res.statusCode = 404
  res.end()
}).listen(port, '0.0.0.0', () => console.log(`node-v2-tools (legacy: ${legacy}) on :${port}/mcp`))
