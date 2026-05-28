import { getApiBaseUrl } from '@/services/api/httpClient'
import { ref } from 'vue'

export interface StepCompleteEvent {
  type: 'step_complete' | 'step_error'
  user_id: number
  conversation_id: number
  message_id: number
  step_index: number
  capability: string
  result: {
    status: 'complete' | 'error'
    file?: { path: string; type: string }
    content?: string
    error?: string
  }
}

type StepEventHandler = (event: StepCompleteEvent) => void

let socket: WebSocket | null = null
let reconnectTimer: ReturnType<typeof setTimeout> | null = null
let reconnectAttempts = 0
const MAX_RECONNECT_ATTEMPTS = 10
const BASE_RECONNECT_DELAY = 2000

const handlers = new Set<StepEventHandler>()
export const wsConnected = ref(false)

function getWsUrl(): string {
  const apiUrl = getApiBaseUrl()
  if (!apiUrl || apiUrl === '') {
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
    return `${proto}//${window.location.hostname}:3002`
  }
  try {
    const url = new URL(apiUrl)
    const proto = url.protocol === 'https:' ? 'wss:' : 'ws:'
    return `${proto}//${url.hostname}:3002`
  } catch {
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
    return `${proto}//${window.location.hostname}:3002`
  }
}

async function getToken(): Promise<string | null> {
  try {
    const response = await fetch(`${getApiBaseUrl()}/api/v1/auth/token`, {
      credentials: 'include',
    })
    if (!response.ok) return null
    const data = await response.json()
    return data.token || null
  } catch {
    return null
  }
}

export function onStepEvent(handler: StepEventHandler): () => void {
  handlers.add(handler)
  return () => {
    handlers.delete(handler)
  }
}

export async function connectWs(): Promise<void> {
  if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
    return
  }

  const token = await getToken()
  if (!token) return

  const wsUrl = `${getWsUrl()}?token=${encodeURIComponent(token)}`

  try {
    socket = new WebSocket(wsUrl)
  } catch {
    scheduleReconnect()
    return
  }

  socket.onopen = () => {
    wsConnected.value = true
    reconnectAttempts = 0
  }

  socket.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data) as StepCompleteEvent
      if (data.type === 'step_complete' || data.type === 'step_error') {
        for (const handler of handlers) {
          handler(data)
        }
      }
    } catch {
      // ignore malformed messages
    }
  }

  socket.onclose = () => {
    wsConnected.value = false
    socket = null
    scheduleReconnect()
  }

  socket.onerror = () => {
    wsConnected.value = false
  }
}

export function disconnectWs(): void {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  reconnectAttempts = MAX_RECONNECT_ATTEMPTS
  if (socket) {
    socket.close()
    socket = null
  }
  wsConnected.value = false
}

function scheduleReconnect(): void {
  if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) return
  if (reconnectTimer) return

  const delay = BASE_RECONNECT_DELAY * Math.pow(1.5, reconnectAttempts)
  reconnectAttempts++

  reconnectTimer = setTimeout(() => {
    reconnectTimer = null
    connectWs()
  }, Math.min(delay, 30000))
}
