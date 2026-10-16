// OP0 spike — not product code. Started by ../../run.sh
// (@modelcontextprotocol/sdk@1.32.1, express@5.3.0, zod@4.6.7).
import express from 'express'
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import { z } from 'zod'

const app = express()
app.use(express.json())

app.post('/mcp', async (req, res) => {
  const server = new McpServer({ name: 'node-tools', version: '1.0.0' })
  server.registerTool(
    'slugify',
    {
      title: 'Slugify',
      description: 'Turn a title into a URL slug.',
      inputSchema: { text: z.string() },
      annotations: { readOnlyHint: true },
    },
    async ({ text }) => {
      const slug = text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
      return { content: [{ type: 'text', text: JSON.stringify({ slug, runtime: 'node' }) }] }
    }
  )
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined })
  res.on('close', () => {
    transport.close()
    server.close()
  })
  await server.connect(transport)
  await transport.handleRequest(req, res, req.body)
})

app.listen(8000, '0.0.0.0', () => console.log('node-tools MCP on :8000/mcp'))
