/**
 * Environment setup file that runs BEFORE any other imports
 * This file sets up browser globals that are required for modules like i18n
 */

// Fix happy-dom's Node.prototype.nodeName getter for DOMPurify >=3.4.8 compatibility.
// DOMPurify uses lookupGetter(Node.prototype, 'nodeName') as an anti-clobbering measure,
// but happy-dom's Node.prototype getter returns '' for all node types. The correct
// values are on subclass prototypes (Element, DocumentType) or implied by nodeType.
if (typeof window !== 'undefined') {
  const NP = window.Node?.prototype
  const origGet = NP && Object.getOwnPropertyDescriptor(NP, 'nodeName')?.get
  if (origGet) {
    const elemGet = Object.getOwnPropertyDescriptor(
      window.Element?.prototype ?? {},
      'nodeName'
    )?.get
    const dtGet = Object.getOwnPropertyDescriptor(
      window.DocumentType?.prototype ?? {},
      'nodeName'
    )?.get
    Object.defineProperty(NP, 'nodeName', {
      get(this: Node) {
        if (elemGet && this instanceof window.Element) return elemGet.call(this)
        if (dtGet && this instanceof window.DocumentType) return dtGet.call(this)
        if (this.nodeType === 3) return '#text'
        if (this.nodeType === 8) return '#comment'
        if (this.nodeType === 9) return '#document'
        if (this.nodeType === 11) return '#document-fragment'
        if (this.nodeType === 7) return (this as ProcessingInstruction).target ?? ''
        return origGet.call(this)
      },
      configurable: true,
    })
  }
}

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
