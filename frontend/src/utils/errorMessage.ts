/**
 * Safe message extraction for catch blocks and unknown errors.
 */
export function getErrorMessage(error: unknown): string {
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
  return 'Unknown error'
}

/** Axios-style errors with `response.data.error` (legacy API clients). */
export function getApiErrorMessage(error: unknown): string {
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
