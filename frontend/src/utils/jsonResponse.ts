export interface ParsedBTextResult {
  text?: string
  data?: Record<string, any>
  remainder?: string
}

interface JsonExtractionResult {
  jsonString: string | null
  remainder: string
}

function extractJsonString(raw: string): JsonExtractionResult {
  if (!raw) {
    return { jsonString: null, remainder: '' }
  }

  const trimmed = raw.trim()
  const firstBrace = trimmed.indexOf('{')

  if (firstBrace === -1) {
    return { jsonString: null, remainder: trimmed }
  }

  let depth = 0
  let inString = false
  let isEscaped = false
  let startIndex = -1

  for (let i = firstBrace; i < trimmed.length; i++) {
    const char = trimmed[i]

    if (inString) {
      if (isEscaped) {
        isEscaped = false
      } else if (char === '\\') {
        isEscaped = true
      } else if (char === '"') {
        inString = false
      }
      continue
    }

    if (char === '"') {
      inString = true
      continue
    }

    if (char === '{') {
      if (depth === 0) {
        startIndex = i
      }
      depth++
      continue
    }

    if (char === '}') {
      depth--
      if (depth === 0 && startIndex !== -1) {
        const jsonString = trimmed.slice(startIndex, i + 1)
        const remainder = trimmed.slice(i + 1).trimStart()
        return { jsonString, remainder }
      }
    }
  }

  return { jsonString: null, remainder: trimmed }
}

export function extractBTextPayload(content: string): ParsedBTextResult {
  if (!content) {
    return {}
  }

  const { jsonString, remainder } = extractJsonString(content)
  if (!jsonString) {
    return {}
  }

  try {
    const data = JSON.parse(jsonString)
    if (data && typeof data === 'object' && 'BTEXT' in data) {
      const textValue = typeof data.BTEXT === 'string' ? data.BTEXT : ''
      return {
        text: textValue,
        data,
        remainder: remainder || undefined,
      }
    }
    return { data, remainder: remainder || undefined }
  } catch (error) {
    console.warn('Failed to parse JSON payload', error)
    return {}
  }
}
