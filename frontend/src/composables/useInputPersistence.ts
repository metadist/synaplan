/**
 * Composable for persisting user input across reloads/auth
 * Saves chat messages and file selections to localStorage
 */

import { watch, onBeforeUnmount, type Ref } from 'vue'

interface PersistedInput {
  message?: string
  timestamp: number
}

interface PersistedFiles {
  files: Array<{
    name: string
    size: number
    type: string
    lastModified: number
  }>
  timestamp: number
}

const STORAGE_KEY_PREFIX = 'synaplan_input_'
const MAX_AGE_MS = 30 * 60 * 1000 // 30 minutes

/**
 * Persist chat input message
 * Uses chat-specific storage key to prevent drafts appearing in wrong chats
 */
export function useInputPersistence(baseKey: string, chatId?: Ref<number | null>) {
  /**
   * Get storage key for current chat
   */
  function getStorageKey(): string {
    const id = chatId?.value
    if (id !== undefined && id !== null) {
      return `${STORAGE_KEY_PREFIX}${baseKey}_${id}`
    }
    return `${STORAGE_KEY_PREFIX}${baseKey}`
  }

  /**
   * Save input to localStorage
   */
  function saveInput(message: string) {
    if (!message.trim()) {
      // Don't save empty messages
      clearInput()
      return
    }

    const data: PersistedInput = {
      message,
      timestamp: Date.now(),
    }

    try {
      localStorage.setItem(getStorageKey(), JSON.stringify(data))
    } catch (error) {
      console.error('Failed to save input to localStorage:', error)
    }
  }

  /**
   * Load input from localStorage
   */
  function loadInput(): string | null {
    try {
      const stored = localStorage.getItem(getStorageKey())
      if (!stored) return null

      const data: PersistedInput = JSON.parse(stored)

      // Check age
      if (Date.now() - data.timestamp > MAX_AGE_MS) {
        clearInput()
        return null
      }

      return data.message || null
    } catch (error) {
      console.error('Failed to load input from localStorage:', error)
      return null
    }
  }

  /**
   * Clear input from localStorage
   */
  function clearInput() {
    try {
      localStorage.removeItem(getStorageKey())
    } catch (error) {
      console.error('Failed to clear input from localStorage:', error)
    }
  }

  return {
    saveInput,
    loadInput,
    clearInput,
  }
}

/**
 * Persist file selections
 * Note: Can't store File objects directly, only metadata
 * User will need to re-select files after reload
 */
export function useFilePersistence(storageKey: string) {
  const fullKey = STORAGE_KEY_PREFIX + 'files_' + storageKey

  /**
   * Save file metadata (not the actual files!)
   */
  function saveFileMetadata(files: File[]) {
    if (files.length === 0) {
      clearFiles()
      return
    }

    const data: PersistedFiles = {
      files: files.map((f) => ({
        name: f.name,
        size: f.size,
        type: f.type,
        lastModified: f.lastModified,
      })),
      timestamp: Date.now(),
    }

    try {
      localStorage.setItem(fullKey, JSON.stringify(data))
    } catch (error) {
      console.error('Failed to save file metadata to localStorage:', error)
    }
  }

  /**
   * Load file metadata (returns file info for display)
   */
  function loadFileMetadata(): PersistedFiles['files'] | null {
    try {
      const stored = localStorage.getItem(fullKey)
      if (!stored) return null

      const data: PersistedFiles = JSON.parse(stored)

      // Check age
      if (Date.now() - data.timestamp > MAX_AGE_MS) {
        clearFiles()
        return null
      }

      return data.files
    } catch (error) {
      console.error('Failed to load file metadata from localStorage:', error)
      return null
    }
  }

  /**
   * Clear file metadata from localStorage
   */
  function clearFiles() {
    try {
      localStorage.removeItem(fullKey)
    } catch (error) {
      console.error('Failed to clear file metadata from localStorage:', error)
    }
  }

  return {
    saveFileMetadata,
    loadFileMetadata,
    clearFiles,
  }
}

/**
 * Auto-persist input as user types
 */
export function useAutoPersist(
  inputRef: Ref<string>,
  baseKey: string,
  chatId?: Ref<number | null>
) {
  const { saveInput, loadInput, clearInput } = useInputPersistence(baseKey, chatId)

  // Load persisted input on mount
  const persisted = loadInput()
  if (persisted && !inputRef.value) {
    inputRef.value = persisted
  }

  // Auto-save as user types (debounced)
  let saveTimeout: ReturnType<typeof setTimeout> | null = null
  watch(
    inputRef,
    (newValue) => {
      if (saveTimeout) clearTimeout(saveTimeout)

      saveTimeout = setTimeout(() => {
        saveInput(newValue)
      }, 500) // Debounce 500ms
    },
    { immediate: false }
  )

  // Clear on unmount if input is empty
  onBeforeUnmount(() => {
    if (!inputRef.value.trim()) {
      clearInput()
    }
  })

  return {
    clearInput,
  }
}
