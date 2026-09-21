import { ref } from 'vue'

export type DialogChoice = 'confirm' | 'extra'

export interface DialogOptions {
  title: string
  message: string
  type?: 'confirm' | 'prompt' | 'alert' | 'choice'
  confirmText?: string
  cancelText?: string
  extraText?: string
  placeholder?: string
  defaultValue?: string
  danger?: boolean
}

export interface DialogState extends DialogOptions {
  isOpen: boolean
  resolve?:
    | ((value: boolean) => void)
    | ((value: string | null) => void)
    | ((value: DialogChoice | null) => void)
    | (() => void)
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

  const choose = (options: Omit<DialogOptions, 'type'>): Promise<DialogChoice | null> => {
    return new Promise((resolve) => {
      dialog.value = {
        ...options,
        type: 'choice',
        isOpen: true,
        confirmText: options.confirmText || 'Confirm',
        cancelText: options.cancelText || 'Cancel',
        resolve: (value: DialogChoice | null) => {
          dialog.value.isOpen = false
          resolve(value)
        },
      }
    })
  }

  const close = () => {
    const resolve = dialog.value.resolve
    if (resolve) {
      if (dialog.value.type === 'confirm') {
        ;(resolve as (value: boolean) => void)(false)
      } else if (dialog.value.type === 'prompt') {
        ;(resolve as (value: string | null) => void)(null)
      } else if (dialog.value.type === 'choice') {
        ;(resolve as (value: DialogChoice | null) => void)(null)
      }
    }
    dialog.value.isOpen = false
  }

  return {
    dialog,
    confirm,
    prompt,
    alert,
    choose,
    close,
  }
}
