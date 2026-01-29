/**
 * Environment setup file that runs BEFORE any other imports
 * This file sets up browser globals that are required for modules like i18n
 */

// Mock localStorage for happy-dom
const localStorageMock = {
  store: {} as Record<string, string>,
  getItem(key: string): string | null {
    return this.store[key] ?? null
  },
  setItem(key: string, value: string): void {
    this.store[key] = value
  },
  removeItem(key: string): void {
    delete this.store[key]
  },
  clear(): void {
    this.store = {}
  },
  get length(): number {
    return Object.keys(this.store).length
  },
  key(index: number): string | null {
    return Object.keys(this.store)[index] ?? null
  },
}

// Set up localStorage on all possible global objects
;(globalThis as unknown as { localStorage: typeof localStorageMock }).localStorage =
  localStorageMock

if (typeof window !== 'undefined') {
  ;(window as unknown as { localStorage: typeof localStorageMock }).localStorage = localStorageMock
}

if (typeof global !== 'undefined') {
  ;(global as unknown as { localStorage: typeof localStorageMock }).localStorage = localStorageMock
}

// Export to prevent tree-shaking
export const __setupComplete = true
