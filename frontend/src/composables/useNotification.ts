import { ref } from 'vue'

export interface NotificationAction {
  label: string
  onClick: () => void
}

export interface Notification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  message: string
  duration?: number
  /** Optional title shown above the message (e.g. for richer toasts). */
  title?: string
  /** Optional media thumbnail (e.g. a finished render). */
  thumbnailUrl?: string
  /** Optional primary action button (e.g. "View"). */
  action?: NotificationAction
}

const notifications = ref<Notification[]>([])

export const useNotification = () => {
  /**
   * Add a fully-specified notification (title, thumbnail, action). Backwards-
   * compatible richer entry point used by actionable toasts (e.g. media-job
   * completion). Returns the generated id.
   */
  const push = (notification: Omit<Notification, 'id'>): string => {
    const id = `notification-${Date.now()}-${Math.random()}`
    const duration = notification.duration ?? 5000

    notifications.value.push({ ...notification, id, duration })

    if (duration > 0) {
      setTimeout(() => {
        remove(id)
      }, duration)
    }

    return id
  }

  const notify = (type: Notification['type'], message: string, duration: number = 5000) => {
    push({ type, message, duration })
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
    push,
    notify,
    success,
    error,
    warning,
    info,
    remove,
  }
}
