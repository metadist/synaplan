import { ref } from 'vue'

export interface Notification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
  duration?: number
}

const notifications = ref<Notification[]>([])

export const useNotification = () => {
  const notify = (type: Notification['type'], message: string, duration: number = 5000) => {
    const id = `notification-${Date.now()}-${Math.random()}`

    const notification: Notification = {
      id,
      type,
      message,
      duration,
    }

    notifications.value.push(notification)

    if (duration > 0) {
      setTimeout(() => {
        remove(id)
      }, duration)
    }
  }

  const success = (message: string, duration?: number) => {
    notify('success', message, duration)
  }

  const error = (message: string, duration?: number) => {
    notify('error', message, duration)
  }

  const warning = (message: string, duration?: number) => {
    notify('warning', message, duration)
  }

  const info = (message: string, duration?: number) => {
    notify('info', message, duration)
  }

  const remove = (id: string) => {
    const index = notifications.value.findIndex((n) => n.id === id)
    if (index !== -1) {
      notifications.value.splice(index, 1)
    }
  }

  return {
    notifications,
    notify,
    success,
    error,
    warning,
    info,
    remove,
  }
}
