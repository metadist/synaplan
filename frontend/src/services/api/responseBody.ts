/** How `httpClient` reads a response body. `file` keeps the server's file name. */
export type ResponseType = 'json' | 'blob' | 'text' | 'arrayBuffer' | 'file'

export async function readResponseBody(response: Response, type: ResponseType): Promise<unknown> {
  switch (type) {
    case 'blob':
      return response.blob()
    case 'text':
      return response.text()
    case 'arrayBuffer':
      return response.arrayBuffer()
    case 'file': {
      const blob = await response.blob()
      const name = fileNameFromDisposition(response.headers?.get('content-disposition') ?? null)
      return new File([blob], name, { type: blob.type })
    }
    case 'json':
    default:
      return response.json()
  }
}

/**
 * The file name a download response names in `Content-Disposition`, the
 * UTF-8 `filename*` form first. Never a path; empty when there is none.
 */
export function fileNameFromDisposition(header: string | null): string {
  if (!header) return ''
  return dispositionName(header).replace(/[/\\]/g, '_')
}

function dispositionName(header: string): string {
  const extended = /filename\*\s*=\s*utf-8''([^;]+)/i.exec(header)
  if (extended?.[1]) {
    try {
      return decodeURIComponent(extended[1].trim())
    } catch {
      // A broken encoding falls back to the plain name below.
    }
  }
  const plain = /filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]+))/.exec(header)
  return plain?.[1]?.replace(/\\(.)/g, '$1') ?? plain?.[2]?.trim() ?? ''
}
