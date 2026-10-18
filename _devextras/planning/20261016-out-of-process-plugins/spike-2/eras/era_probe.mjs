// Review spike (2026-10-18) — not product code. Node 22, no dependencies.
// For each MCP endpoint: does it answer the legacy handshake Synaplan's
// McpClient sends today (`initialize`, 2025-11-25), and the modern
// 2026-07-28 probe (`server/discover` with the per-request `_meta`)?
//
//   node era_probe.mjs http://host:8000/mcp [...]

const LEGACY = '2025-11-25'
const MODERN = '2026-07-28'

const post = async (url, headers, body) => {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
    body: JSON.stringify(body),
  })
  const text = await res.text()
  const json = text.startsWith('{') ? text : (text.split('\n').filter((l) => l.startsWith('data:')).pop() ?? '').slice(5)
  let parsed = null
  try {
    parsed = JSON.parse(json)
  } catch {
    parsed = null
  }
  return { status: res.status, rpc: parsed }
}

const describe = ({ status, rpc }, pick) => {
  if (rpc?.error) return `HTTP ${status}, error ${rpc.error.code} "${rpc.error.message}"`
  if (rpc?.result) return `HTTP ${status}, ${pick(rpc.result)}`
  return `HTTP ${status}, no JSON-RPC answer`
}

for (const url of process.argv.slice(2)) {
  const legacy = await post(
    url,
    { 'MCP-Protocol-Version': LEGACY },
    { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: LEGACY, capabilities: {}, clientInfo: { name: 'era-probe', version: '1' } } }
  ).catch((e) => ({ status: 0, rpc: { error: { code: 'network', message: e.message } } }))
  const modern = await post(
    url,
    { 'MCP-Protocol-Version': MODERN, 'Mcp-Method': 'server/discover' },
    {
      jsonrpc: '2.0',
      id: 1,
      method: 'server/discover',
      params: {
        _meta: {
          'io.modelcontextprotocol/protocolVersion': MODERN,
          'io.modelcontextprotocol/clientInfo': { name: 'era-probe', version: '1' },
          'io.modelcontextprotocol/clientCapabilities': {},
        },
      },
    }
  ).catch((e) => ({ status: 0, rpc: { error: { code: 'network', message: e.message } } }))

  console.log(`=== ${url}`)
  console.log(`    legacy initialize ${LEGACY}: ${describe(legacy, (r) => `protocolVersion ${r.protocolVersion}`)}`)
  console.log(`    modern server/discover ${MODERN}: ${describe(modern, (r) => `supportedVersions ${JSON.stringify(r.supportedVersions)}`)}`)
}
