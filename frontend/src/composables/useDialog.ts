import { ref } from 'vue'

export interface DialogOptions {
  title: string
  message: string
  type?: 'confirm' | 'prompt' | 'alert'
  confirmText?: string
  cancelText?: string
  placeholder?: string
  defaultValue?: string
  danger?: boolean
}

export interface DialogState extends DialogOptions {
  isOpen: boolean
  resolve?: (value: any) => void
  reject?: () => void
}

const dialog = ref<DialogState>({
  isOpen: false,
  title: '',
  message: '',
  type: 'confirm',
})

export const useDialog = () => {
  const confirm = (options: Omit<DialogOptions, 'type'>): Promise<boolean> => {
    return new Promise((resolve) => {
      dialog.value = {
        ...options,
        type: 'confirm',
        isOpen: true,
        confirmText: options.confirmText || 'Confirm',
        cancelText: options.cancelText || 'Cancel',
        resolve: (value: boolean) => {
          dialog.value.isOpen = false
          resolve(value)
        },
      }
    })
  }

  const prompt = (options: Omit<DialogOptions, 'type'>): Promise<string | null> => {
    return new Promise((resolve) => {
      dialog.value = {
        ...options,
        type: 'prompt',
        isOpen: true,
        confirmText: options.confirmText || 'OK',
        cancelText: options.cancelText || 'Cancel',
        resolve: (value: string | null) => {
          dialog.value.isOpen = false
          resolve(value)
        },
      }
    })
  }

  const alert = (options: Omit<DialogOptions, 'type' | 'cancelText'>): Promise<void> => {
    return new Promise((resolve) => {
      dialog.value = {
        ...options,
        type: 'alert',
        isOpen: true,
        confirmText: options.confirmText || 'OK',
        resolve: () => {
          dialog.value.isOpen = false
          resolve()
        },
      }
    })
  }

  const close = () => {
    if (dialog.value.resolve) {
      if (dialog.value.type === 'confirm') {
        dialog.value.resolve(false)
      } else if (dialog.value.type === 'prompt') {
        dialog.value.resolve(null)
      }
    }
    dialog.value.isOpen = false
  }

  return {
    dialog,
    confirm,
    prompt,
    alert,
    close,
  }
}
