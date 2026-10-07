export interface ChatArtifact {
  language: 'html' | 'svg'
  code: string
  index: number
}

const FENCE = /```(html|svg)\s*\n([\s\S]*?)```/gi

/** Complete HTML documents and SVG images. Mermaid stays a code block. */
export function extractArtifacts(text: string): ChatArtifact[] {
  const found: ChatArtifact[] = []
  for (const match of text.matchAll(FENCE)) {
    const language = match[1].toLowerCase()
    const code = match[2].trim()
    if (language !== 'html' && language !== 'svg') continue
    if (code === '') continue
    if (language === 'html' && !/<html[\s>]/i.test(code) && !/<!doctype html/i.test(code)) {
      continue
    }
    if (language === 'svg' && !/<svg[\s>]/i.test(code)) continue
    found.push({ language, code, index: found.length })
  }
  return found
}

/** Drop script, event handlers, and external URLs before an SVG is shown. */
export function sanitizeSvg(source: string): string {
  if (typeof DOMParser === 'undefined') return ''
  const doc = new DOMParser().parseFromString(source, 'image/svg+xml')
  doc.querySelectorAll('script, foreignObject').forEach((node) => node.remove())
  const svg = doc.querySelector('svg')
  if (!svg) return ''
  const strip = (el: Element) => {
    for (const attr of [...el.attributes]) {
      const name = attr.name.toLowerCase()
      const external = /^(https?:|\/\/)/i.test(attr.value.trim())
      if (
        name.startsWith('on') ||
        (external && (name === 'href' || name.endsWith(':href') || name === 'src'))
      ) {
        el.removeAttribute(attr.name)
      }
    }
    for (const child of [...el.children]) strip(child)
  }
  strip(svg)
  return svg.outerHTML
}

const CSP = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:;">`

function withCsp(html: string): string {
  if (/<head[^>]*>/i.test(html)) {
    return html.replace(/<head[^>]*>/i, (head) => `${head}${CSP}`)
  }
  return `<!DOCTYPE html><html><head>${CSP}</head><body>${html}</body></html>`
}

export function artifactSrcDoc(artifact: ChatArtifact): string {
  if (artifact.language === 'svg') {
    const svg = sanitizeSvg(artifact.code)
    return `<!DOCTYPE html><html><head>${CSP}<style>html,body{margin:0;background:#fff;}svg{max-width:100%;height:auto;}</style></head><body>${svg}</body></html>`
  }
  return withCsp(artifact.code)
}
