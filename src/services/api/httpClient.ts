/**
 * HTTP Client - Base HTTP functionality
 */

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

interface HttpClientOptions extends RequestInit {
  params?: Record<string, string>
}

async function httpClient<T>(endpoint: string, options: HttpClientOptions = {}): Promise<T> {
  const { params, ...fetchOptions } = options
  
  let url = `${API_BASE_URL}${endpoint}`
  
  if (params) {
    const queryString = new URLSearchParams(params).toString()
    url += `?${queryString}`
  }

  const token = localStorage.getItem('auth_token')
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    ...options.headers,
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(url, {
    ...fetchOptions,
    headers,
  })

  if (!response.ok) {
    if (response.status === 401) {
      // Token invalid, trigger logout
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      window.location.href = '/login'
    }
    
    let errorMessage = `HTTP ${response.status}: ${response.statusText}`
    try {
      const errorData = await response.json()
      errorMessage = errorData.error || errorData.message || errorMessage
    } catch {
      // Use default error message
    }
    throw new Error(errorMessage)
  }

  return response.json()
}

export { httpClient, API_BASE_URL }

