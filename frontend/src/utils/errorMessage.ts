/**
 * Safe message extraction for catch blocks and unknown errors.
 * Returns null when no meaningful message can be derived,
 * so callers can use `getErrorMessage(err) || 'Friendly fallback'`.
 */
export function getErrorMessage(error: unknown): string | null {
  if (error instanceof Error) {
    return error.message
  }
  if (typeof error === 'string') {
    return error
  }
  if (
    error &&
    typeof error === 'object' &&
    'message' in error &&
    typeof (error as { message: unknown }).message === 'string'
  ) {
    return (error as { message: string }).message
  }
  return null
}

/** Axios-style errors with `response.data.error` (legacy API clients). */
export function getApiErrorMessage(error: unknown): string | null {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { error?: string; message?: string } } })
      .response
    const fromBody = response?.data?.error ?? response?.data?.message
    if (typeof fromBody === 'string' && fromBody.length > 0) {
      return fromBody
    }
  }
  return getErrorMessage(error)
}
